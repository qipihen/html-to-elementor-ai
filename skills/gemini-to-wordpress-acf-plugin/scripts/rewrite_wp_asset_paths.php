#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run in CLI mode.\n");
    exit(1);
}

function usage(): void
{
    $msg = <<<TXT
Usage:
  php rewrite_wp_asset_paths.php --input <file-or-dir> --output <file-or-dir>
      [--site-root <root-dir>] [--mode token|theme-php]
      [--report <report.md|report.json>] [--report-format md|json]

Purpose:
  Rewrite static HTML asset references into WordPress-friendly paths.

Modes:
  token      {{THEME_URI}}/path/to/asset   (default)
  theme-php  <?php echo esc_url( get_stylesheet_directory_uri() . '/path/to/asset' ); ?>

Notes:
  - Rewrites asset URLs in src/href/poster/data-* attrs, srcset, and inline style url(...)
  - Keeps external URLs (http/https/data/mailto/tel/javascript/#) unchanged
  - Keeps non-asset page links (e.g., href="about.html") unchanged
TXT;
    fwrite(STDOUT, $msg . "\n");
}

function is_external_or_special(string $url): bool
{
    $u = trim($url);
    if ($u === '') {
        return true;
    }
    if ($u[0] === '#') {
        return true;
    }
    $lower = strtolower($u);
    $prefixes = ['http://', 'https://', '//', 'data:', 'mailto:', 'tel:', 'javascript:'];
    foreach ($prefixes as $p) {
        if (str_starts_with($lower, $p)) {
            return true;
        }
    }
    return false;
}

function normalize_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $isAbs = str_starts_with($path, '/');
    $segments = explode('/', $path);
    $stack = [];
    foreach ($segments as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            if (count($stack) > 0) {
                array_pop($stack);
            }
            continue;
        }
        $stack[] = $seg;
    }
    $result = implode('/', $stack);
    return $isAbs ? '/' . $result : $result;
}

function split_url_parts(string $raw): array
{
    $hashPos = strpos($raw, '#');
    $fragment = '';
    $beforeHash = $raw;
    if ($hashPos !== false) {
        $fragment = substr($raw, $hashPos);
        $beforeHash = substr($raw, 0, $hashPos);
    }

    $queryPos = strpos($beforeHash, '?');
    $query = '';
    $path = $beforeHash;
    if ($queryPos !== false) {
        $query = substr($beforeHash, $queryPos);
        $path = substr($beforeHash, 0, $queryPos);
    }

    return [$path, $query, $fragment];
}

function is_asset_like_path(string $path): bool
{
    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === '') {
        return false;
    }

    $assetExt = [
        'css', 'js', 'mjs',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'avif', 'ico', 'bmp',
        'mp4', 'webm', 'ogg', 'mp3', 'wav',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        'pdf', 'zip', 'json', 'xml',
    ];
    return in_array($ext, $assetExt, true);
}

function build_wp_path(string $assetRelPath, string $mode): string
{
    $assetRelPath = ltrim($assetRelPath, '/');
    if ($mode === 'theme-php') {
        return "<?php echo esc_url( get_stylesheet_directory_uri() . '/{$assetRelPath}' ); ?>";
    }
    return "{{THEME_URI}}/{$assetRelPath}";
}

function resolve_asset_rel_path(string $fileRelDir, string $rawPath): string
{
    if (str_starts_with($rawPath, '/')) {
        return ltrim(normalize_path($rawPath), '/');
    }
    $combined = $fileRelDir === '' ? $rawPath : ($fileRelDir . '/' . $rawPath);
    return ltrim(normalize_path($combined), '/');
}

function rewrite_ref(
    string $rawRef,
    string $attrName,
    string $fileRelDir,
    string $siteRoot,
    string $mode,
    array &$stats
): string {
    $stats['refs_total']++;

    if (is_external_or_special($rawRef)) {
        $stats['refs_skipped_external']++;
        return $rawRef;
    }

    [$path, $query, $fragment] = split_url_parts($rawRef);
    if ($path === '') {
        $stats['refs_skipped_other']++;
        return $rawRef;
    }

    if (strtolower($attrName) === 'href' && !is_asset_like_path($path)) {
        $stats['refs_skipped_other']++;
        return $rawRef;
    }

    $assetRel = resolve_asset_rel_path($fileRelDir, $path);
    if ($assetRel === '') {
        $stats['refs_skipped_other']++;
        return $rawRef;
    }

    $abs = rtrim($siteRoot, '/') . '/' . $assetRel;
    if (!is_file($abs)) {
        $stats['missing_files'][$assetRel] = true;
    }

    $newRef = build_wp_path($assetRel, $mode) . $query . $fragment;
    if ($newRef !== $rawRef) {
        $stats['refs_rewritten']++;
    }
    return $newRef;
}

function rewrite_html_content(string $html, string $fileAbsPath, string $siteRoot, string $mode, array &$stats): string
{
    $fileDirAbs = dirname($fileAbsPath);
    $fileRelDir = '';
    if (str_starts_with($fileDirAbs, $siteRoot)) {
        $fileRelDir = ltrim(substr($fileDirAbs, strlen($siteRoot)), '/');
    }

    $attrPattern = '/\b(src|href|poster|data-src|data-bg|data-background|data-lazy-src)\s*=\s*("([^"]*)"|\'([^\']*)\')/i';
    $html = preg_replace_callback(
        $attrPattern,
        static function (array $m) use ($fileRelDir, $siteRoot, $mode, &$stats): string {
            $attr = $m[1];
            $quoted = $m[2];
            $value = $m[3] !== '' ? $m[3] : $m[4];
            $quoteChar = $quoted[0];
            $newValue = rewrite_ref($value, $attr, $fileRelDir, $siteRoot, $mode, $stats);
            return $attr . '=' . $quoteChar . $newValue . $quoteChar;
        },
        $html
    ) ?? $html;

    $srcsetPattern = '/\bsrcset\s*=\s*("([^"]*)"|\'([^\']*)\')/i';
    $html = preg_replace_callback(
        $srcsetPattern,
        static function (array $m) use ($fileRelDir, $siteRoot, $mode, &$stats): string {
            $quoted = $m[1];
            $value = $m[2] !== '' ? $m[2] : $m[3];
            $quoteChar = $quoted[0];

            $parts = array_map('trim', explode(',', $value));
            $newParts = [];
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }
                $seg = preg_split('/\s+/', $part, 2);
                $url = (string) ($seg[0] ?? '');
                $descriptor = (string) ($seg[1] ?? '');
                $newUrl = rewrite_ref($url, 'srcset', $fileRelDir, $siteRoot, $mode, $stats);
                $newParts[] = trim($newUrl . ' ' . $descriptor);
            }
            return 'srcset=' . $quoteChar . implode(', ', $newParts) . $quoteChar;
        },
        $html
    ) ?? $html;

    $stylePattern = '/\bstyle\s*=\s*("([^"]*)"|\'([^\']*)\')/i';
    $html = preg_replace_callback(
        $stylePattern,
        static function (array $m) use ($fileRelDir, $siteRoot, $mode, &$stats): string {
            $quoted = $m[1];
            $value = $m[2] !== '' ? $m[2] : $m[3];
            $quoteChar = $quoted[0];
            $newStyle = preg_replace_callback(
                '/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i',
                static function (array $u) use ($fileRelDir, $siteRoot, $mode, &$stats): string {
                    $q = $u[1];
                    $url = $u[2];
                    $newUrl = rewrite_ref($url, 'style-url', $fileRelDir, $siteRoot, $mode, $stats);
                    return 'url(' . $q . $newUrl . $q . ')';
                },
                $value
            ) ?? $value;

            return 'style=' . $quoteChar . $newStyle . $quoteChar;
        },
        $html
    ) ?? $html;

    return $html;
}

function write_report(array $stats, string $input, string $output, ?string $reportPath, string $format): void
{
    if ($reportPath === null || trim($reportPath) === '') {
        return;
    }

    $missing = array_keys($stats['missing_files']);
    sort($missing);
    $payload = [
        'input' => $input,
        'output' => $output,
        'files_processed' => $stats['files_processed'],
        'refs_total' => $stats['refs_total'],
        'refs_rewritten' => $stats['refs_rewritten'],
        'refs_skipped_external' => $stats['refs_skipped_external'],
        'refs_skipped_other' => $stats['refs_skipped_other'],
        'missing_files_count' => count($missing),
        'missing_files' => $missing,
    ];

    if ($format === 'json') {
        file_put_contents($reportPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return;
    }

    $lines = [];
    $lines[] = '# Asset Rewrite Report';
    $lines[] = '';
    $lines[] = '- Input: `' . $input . '`';
    $lines[] = '- Output: `' . $output . '`';
    $lines[] = '- Files processed: `' . $payload['files_processed'] . '`';
    $lines[] = '- Refs total: `' . $payload['refs_total'] . '`';
    $lines[] = '- Refs rewritten: `' . $payload['refs_rewritten'] . '`';
    $lines[] = '- Refs skipped external: `' . $payload['refs_skipped_external'] . '`';
    $lines[] = '- Refs skipped other: `' . $payload['refs_skipped_other'] . '`';
    $lines[] = '- Missing files: `' . $payload['missing_files_count'] . '`';
    $lines[] = '';
    if (count($missing) > 0) {
        $lines[] = '## Missing Files';
        foreach ($missing as $m) {
            $lines[] = '- `' . $m . '`';
        }
        $lines[] = '';
    }

    file_put_contents($reportPath, implode("\n", $lines));
}

$options = getopt('', [
    'input:',
    'output:',
    'site-root:',
    'mode:',
    'report:',
    'report-format:',
]);

$input = $options['input'] ?? null;
$output = $options['output'] ?? null;
$siteRootOpt = $options['site-root'] ?? null;
$mode = $options['mode'] ?? 'token';
$report = $options['report'] ?? null;
$reportFormat = $options['report-format'] ?? 'md';

if ($input === null || $output === null) {
    usage();
    exit(1);
}
if (!in_array($mode, ['token', 'theme-php'], true)) {
    fwrite(STDERR, "--mode must be token or theme-php.\n");
    exit(1);
}
if (!in_array($reportFormat, ['md', 'json'], true)) {
    fwrite(STDERR, "--report-format must be md or json.\n");
    exit(1);
}
if (!file_exists($input)) {
    fwrite(STDERR, "Input not found: {$input}\n");
    exit(1);
}

$inputReal = realpath($input);
if ($inputReal === false) {
    fwrite(STDERR, "Failed to resolve input path.\n");
    exit(1);
}

$siteRoot = $siteRootOpt !== null ? realpath($siteRootOpt) : null;
if ($siteRoot === false) {
    fwrite(STDERR, "Failed to resolve --site-root path.\n");
    exit(1);
}

$stats = [
    'files_processed' => 0,
    'refs_total' => 0,
    'refs_rewritten' => 0,
    'refs_skipped_external' => 0,
    'refs_skipped_other' => 0,
    'missing_files' => [],
];

if (is_file($inputReal)) {
    $parent = dirname($output);
    if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
        fwrite(STDERR, "Failed to create output directory: {$parent}\n");
        exit(1);
    }

    $fileRoot = $siteRoot ?? dirname($inputReal);
    $raw = file_get_contents($inputReal);
    if ($raw === false) {
        fwrite(STDERR, "Failed to read input file.\n");
        exit(1);
    }

    $rewritten = rewrite_html_content($raw, $inputReal, $fileRoot, $mode, $stats);
    if (file_put_contents($output, $rewritten) === false) {
        fwrite(STDERR, "Failed to write output file: {$output}\n");
        exit(1);
    }
    $stats['files_processed']++;
    write_report($stats, $inputReal, $output, $report, $reportFormat);
} else {
    $outputDir = $output;
    if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
        fwrite(STDERR, "Failed to create output directory: {$outputDir}\n");
        exit(1);
    }

    $root = $siteRoot ?? $inputReal;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($inputReal, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $entry) {
        $abs = (string) $entry->getPathname();
        $rel = ltrim(substr($abs, strlen($inputReal)), '/');
        $target = rtrim($outputDir, '/') . '/' . $rel;

        if ($entry->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0777, true);
            }
            continue;
        }

        $ext = strtolower((string) pathinfo($abs, PATHINFO_EXTENSION));
        if (in_array($ext, ['html', 'htm', 'php'], true)) {
            $raw = file_get_contents($abs);
            if ($raw === false) {
                continue;
            }
            $rewritten = rewrite_html_content($raw, $abs, $root, $mode, $stats);
            file_put_contents($target, $rewritten);
            $stats['files_processed']++;
        } else {
            copy($abs, $target);
        }
    }

    write_report($stats, $inputReal, $outputDir, $report, $reportFormat);
}

$missingCount = count($stats['missing_files']);
fwrite(STDOUT, "Rewritten refs: {$stats['refs_rewritten']} / {$stats['refs_total']}; missing: {$missingCount}\n");
exit(0);

