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
  php patch_runner.php --input <page.json> (--request "<line1\\nline2>" | --request-file <changes.txt>)
      [--report <preview-report.md>] [--ops-out <ops.json>] [--apply] [--output <patched.json>] [--allow-partial]

Purpose:
  Convert simple human-readable patch requests into Elementor patch ops,
  run dry-run by default, and optionally apply the patch.

Request syntax (one instruction per line):
  set <element_id> <path> = <value>
  replace <element_id> <path> "<find>" -> "<replace>"
  remove <element_id>
  move <element_id> to <parent_id|__root__> [at <start|end|index>]
  merge <element_id> <json-object>

Chinese variants (supported):
  将 <element_id> 的 <path> 改为 <value>
  把 <element_id> 的 <path> 设置为 <value>
  删除 <element_id>
  移动 <element_id> 到 <parent_id|__root__> [位置 <start|end|index>]
TXT;
    fwrite(STDOUT, $msg . "\n");
}

function normalize_value(string $raw)
{
    $v = trim($raw);
    if ($v === '') {
        return '';
    }

    if (
        (str_starts_with($v, '"') && str_ends_with($v, '"')) ||
        (str_starts_with($v, "'") && str_ends_with($v, "'"))
    ) {
        return substr($v, 1, -1);
    }

    $lower = strtolower($v);
    if ($lower === 'true') {
        return true;
    }
    if ($lower === 'false') {
        return false;
    }
    if ($lower === 'null') {
        return null;
    }
    if (is_numeric($v)) {
        return str_contains($v, '.') ? (float) $v : (int) $v;
    }
    if ((str_starts_with($v, '{') && str_ends_with($v, '}')) || (str_starts_with($v, '[') && str_ends_with($v, ']'))) {
        $decoded = json_decode($v, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }

    return $v;
}

function parse_request_line(string $line, int $lineNo): array
{
    $trimmed = trim($line);
    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
        return ['skip' => true];
    }

    if (preg_match('/^set\s+([A-Za-z0-9_-]+)\s+([A-Za-z0-9_.-]+)\s*=\s*(.+)$/i', $trimmed, $m) === 1) {
        return [
            'op' => 'set_setting',
            'element_id' => $m[1],
            'path' => $m[2],
            'value' => normalize_value($m[3]),
            '_source_line' => $lineNo,
            '_source_text' => $trimmed,
        ];
    }

    if (preg_match('/^(?:将|把)\s*([A-Za-z0-9_-]+)\s*的\s*([A-Za-z0-9_.-]+)\s*(?:改为|设置为)\s*(.+)$/u', $trimmed, $m) === 1) {
        return [
            'op' => 'set_setting',
            'element_id' => $m[1],
            'path' => $m[2],
            'value' => normalize_value($m[3]),
            '_source_line' => $lineNo,
            '_source_text' => $trimmed,
        ];
    }

    if (preg_match('/^replace\s+([A-Za-z0-9_-]+)\s+([A-Za-z0-9_.-]+)\s+"(.*)"\s*->\s*"(.*)"$/i', $trimmed, $m) === 1) {
        return [
            'op' => 'replace_text',
            'element_id' => $m[1],
            'path' => $m[2],
            'find' => $m[3],
            'replace' => $m[4],
            '_source_line' => $lineNo,
            '_source_text' => $trimmed,
        ];
    }

    if (preg_match('/^remove\s+([A-Za-z0-9_-]+)$/i', $trimmed, $m) === 1 || preg_match('/^删除\s*([A-Za-z0-9_-]+)$/u', $trimmed, $m) === 1) {
        return [
            'op' => 'remove_element',
            'element_id' => $m[1],
            '_source_line' => $lineNo,
            '_source_text' => $trimmed,
        ];
    }

    if (preg_match('/^move\s+([A-Za-z0-9_-]+)\s+to\s+([A-Za-z0-9_-]+|__root__)(?:\s+at\s+([A-Za-z0-9_-]+))?$/i', $trimmed, $m) === 1) {
        return [
            'op' => 'move_element',
            'element_id' => $m[1],
            'new_parent_id' => $m[2],
            'position' => $m[3] ?? 'end',
            '_source_line' => $lineNo,
            '_source_text' => $trimmed,
        ];
    }

    if (preg_match('/^移动\s*([A-Za-z0-9_-]+)\s*到\s*([A-Za-z0-9_-]+|__root__)(?:\s*位置\s*([A-Za-z0-9_-]+))?$/u', $trimmed, $m) === 1) {
        return [
            'op' => 'move_element',
            'element_id' => $m[1],
            'new_parent_id' => $m[2],
            'position' => $m[3] ?? 'end',
            '_source_line' => $lineNo,
            '_source_text' => $trimmed,
        ];
    }

    if (preg_match('/^merge\s+([A-Za-z0-9_-]+)\s+(.+)$/i', $trimmed, $m) === 1) {
        $settings = json_decode(trim($m[2]), true);
        if (!is_array($settings)) {
            return [
                'error' => "Line {$lineNo}: merge requires a valid JSON object.",
            ];
        }
        return [
            'op' => 'update_settings',
            'element_id' => $m[1],
            'settings' => $settings,
            '_source_line' => $lineNo,
            '_source_text' => $trimmed,
        ];
    }

    return [
        'error' => "Line {$lineNo}: unsupported syntax: {$trimmed}",
    ];
}

function shell_run(string $cmd, array &$out, int &$code): void
{
    $out = [];
    exec($cmd . ' 2>&1', $out, $code);
}

function render_report(array $meta): string
{
    $lines = [];
    $lines[] = "# Patch Preview Report";
    $lines[] = "";
    $lines[] = "- Input: `{$meta['input']}`";
    $lines[] = "- Ops count: `{$meta['ops_count']}`";
    $lines[] = "- Dry-run exit code: `{$meta['dry_code']}`";
    $lines[] = "- Dry-run status: `" . ($meta['dry_ok'] ? 'PASSED' : 'FAILED') . "`";
    if (isset($meta['apply_requested'])) {
        $lines[] = "- Apply requested: `" . ($meta['apply_requested'] ? 'yes' : 'no') . "`";
    }
    if (isset($meta['apply_code'])) {
        $lines[] = "- Apply exit code: `{$meta['apply_code']}`";
    }
    if (!empty($meta['output'])) {
        $lines[] = "- Output: `{$meta['output']}`";
    }
    $lines[] = "";

    $lines[] = "## Parsed Instructions";
    if (empty($meta['parsed'])) {
        $lines[] = "- (none)";
    } else {
        foreach ($meta['parsed'] as $p) {
            $lines[] = "- L{$p['line']}: `{$p['text']}` -> `{$p['op']}`";
        }
    }
    $lines[] = "";

    if (!empty($meta['parse_errors'])) {
        $lines[] = "## Parse Errors";
        foreach ($meta['parse_errors'] as $e) {
            $lines[] = "- {$e}";
        }
        $lines[] = "";
    }

    $lines[] = "## Dry-run Operation Results";
    if (empty($meta['dry_log']['operations'] ?? [])) {
        $lines[] = "- (no operation logs)";
    } else {
        foreach ($meta['dry_log']['operations'] as $op) {
            $idx = (int) ($op['index'] ?? -1);
            $status = (string) ($op['status'] ?? 'unknown');
            $name = (string) ($op['op'] ?? 'unknown');
            $msg = (string) ($op['message'] ?? '');
            $extra = $msg !== '' ? " ({$msg})" : '';
            $lines[] = "- #{$idx} `{$name}` => `{$status}`{$extra}";
        }
    }
    $lines[] = "";

    if (!empty($meta['dry_log']['errors'] ?? [])) {
        $lines[] = "## Dry-run Errors";
        foreach ($meta['dry_log']['errors'] as $err) {
            $lines[] = "- `{$err['op']}`: {$err['message']}";
        }
        $lines[] = "";
    }

    $lines[] = "## Next";
    if (!$meta['dry_ok']) {
        $lines[] = "- Fix request lines first, then rerun preview.";
    } elseif (!($meta['apply_requested'] ?? false)) {
        $lines[] = "- Dry-run passed. Run again with `--apply --output <patched.json>` to write output.";
    } else {
        $lines[] = "- Apply finished. Validate with `validate_elementor_json.php --strict` before import.";
    }

    return implode("\n", $lines) . "\n";
}

$options = getopt('', [
    'input:',
    'request:',
    'request-file:',
    'report:',
    'ops-out:',
    'apply',
    'output:',
    'allow-partial',
]);

$input = $options['input'] ?? null;
$requestInline = $options['request'] ?? null;
$requestFile = $options['request-file'] ?? null;
$reportPath = $options['report'] ?? null;
$opsOutPath = $options['ops-out'] ?? null;
$apply = array_key_exists('apply', $options);
$output = $options['output'] ?? null;
$allowPartial = array_key_exists('allow-partial', $options);

if ($input === null || (($requestInline === null || trim((string) $requestInline) === '') && $requestFile === null)) {
    usage();
    exit(1);
}
if (!is_file($input)) {
    fwrite(STDERR, "Input JSON not found: {$input}\n");
    exit(1);
}
if ($requestFile !== null && !is_file($requestFile)) {
    fwrite(STDERR, "Request file not found: {$requestFile}\n");
    exit(1);
}
if ($apply && ($output === null || trim((string) $output) === '')) {
    $output = preg_replace('/\.json$/i', '', $input) . '.patched.json';
}

$requestText = '';
if ($requestInline !== null && trim((string) $requestInline) !== '') {
    $requestText .= (string) $requestInline . "\n";
}
if ($requestFile !== null) {
    $raw = file_get_contents($requestFile);
    if ($raw === false) {
        fwrite(STDERR, "Failed to read request file.\n");
        exit(1);
    }
    $requestText .= $raw;
}

$lines = preg_split('/\R/u', $requestText) ?: [];
$ops = [];
$parsed = [];
$parseErrors = [];

foreach ($lines as $idx => $line) {
    $parsedOp = parse_request_line($line, $idx + 1);
    if (($parsedOp['skip'] ?? false) === true) {
        continue;
    }
    if (isset($parsedOp['error'])) {
        $parseErrors[] = $parsedOp['error'];
        continue;
    }

    $parsed[] = [
        'line' => $parsedOp['_source_line'],
        'text' => $parsedOp['_source_text'],
        'op' => $parsedOp['op'],
    ];

    unset($parsedOp['_source_line'], $parsedOp['_source_text']);
    $ops[] = $parsedOp;
}

$meta = [
    'input' => $input,
    'ops_count' => count($ops),
    'parsed' => $parsed,
    'parse_errors' => $parseErrors,
    'apply_requested' => $apply,
    'output' => $output,
];

if (!empty($parseErrors) || empty($ops)) {
    if (empty($ops) && empty($parseErrors)) {
        $parseErrors[] = 'No valid instructions found.';
    }
    $meta['dry_code'] = 1;
    $meta['dry_ok'] = false;
    $meta['dry_log'] = ['operations' => [], 'errors' => $parseErrors];
    $report = render_report($meta);
    if ($reportPath !== null) {
        file_put_contents($reportPath, $report);
    }
    fwrite(STDOUT, $report);
    exit(1);
}

$opsDoc = ['operations' => $ops];
$opsJson = json_encode($opsDoc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($opsJson === false) {
    fwrite(STDERR, "Failed to encode generated ops JSON.\n");
    exit(1);
}

$tmpOps = tempnam(sys_get_temp_dir(), 'patch_ops_');
$tmpDryLog = tempnam(sys_get_temp_dir(), 'patch_dry_log_');
if ($tmpOps === false || $tmpDryLog === false) {
    fwrite(STDERR, "Failed to create temp files.\n");
    exit(1);
}
file_put_contents($tmpOps, $opsJson);
if ($opsOutPath !== null) {
    file_put_contents($opsOutPath, $opsJson);
}

$applyScript = __DIR__ . '/apply_elementor_patch.php';
$dryCmd = 'php ' . escapeshellarg($applyScript)
    . ' --input ' . escapeshellarg($input)
    . ' --patch ' . escapeshellarg($tmpOps)
    . ' --dry-run'
    . ' --log ' . escapeshellarg($tmpDryLog);
if ($allowPartial) {
    $dryCmd .= ' --allow-partial';
}

$dryOut = [];
$dryCode = 0;
shell_run($dryCmd, $dryOut, $dryCode);
$dryRaw = file_get_contents($tmpDryLog);
$dryLog = is_string($dryRaw) ? json_decode($dryRaw, true) : null;
if (!is_array($dryLog)) {
    $dryLog = [
        'operations' => [],
        'errors' => [['op' => 'runner', 'message' => 'Dry-run did not produce valid log JSON']],
    ];
}

$meta['dry_code'] = $dryCode;
$meta['dry_ok'] = $dryCode === 0;
$meta['dry_log'] = $dryLog;

$applyCode = null;
if ($apply && $dryCode === 0 && $output !== null) {
    $tmpApplyLog = tempnam(sys_get_temp_dir(), 'patch_apply_log_');
    if ($tmpApplyLog === false) {
        fwrite(STDERR, "Failed to create apply log temp file.\n");
        exit(1);
    }

    $applyCmd = 'php ' . escapeshellarg($applyScript)
        . ' --input ' . escapeshellarg($input)
        . ' --patch ' . escapeshellarg($tmpOps)
        . ' --output ' . escapeshellarg($output)
        . ' --log ' . escapeshellarg($tmpApplyLog);
    if ($allowPartial) {
        $applyCmd .= ' --allow-partial';
    }
    $applyOut = [];
    $applyCode = 0;
    shell_run($applyCmd, $applyOut, $applyCode);
    $meta['apply_code'] = $applyCode;
}

$report = render_report($meta);
if ($reportPath !== null) {
    file_put_contents($reportPath, $report);
}
fwrite(STDOUT, $report);

if ($dryCode !== 0) {
    exit(2);
}
if ($apply && $applyCode !== 0) {
    exit(3);
}
exit(0);
