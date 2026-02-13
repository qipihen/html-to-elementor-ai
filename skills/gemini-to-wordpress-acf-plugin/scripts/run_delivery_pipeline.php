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
  php run_delivery_pipeline.php
    [--category-csv <category.csv>]
    [--detail-csv <detail.csv>]
    [--category-acf <a.json[,b.json]>]
    [--detail-acf <a.json[,b.json]>]
    [--out-dir <dir>]
    [--package-name <name.zip>]
    [--with-seo-guard]
    [--allow-seo-critical]
    [--json]

Purpose:
  One-command pipeline for final import package:
  1) import contract check
  2) optional SEO/fact guard check
  3) package CSV + reports + manifest

Rules:
  - At least one of --category-csv or --detail-csv is required.
  - Default blocks release on contract errors.
  - SEO guard is disabled by default (page-build focused mode).
  - Enable SEO guard with --with-seo-guard.
  - Use --allow-seo-critical only if you explicitly accept SEO risk.
TXT;
    fwrite(STDOUT, $msg . "\n");
}

function normalize_opt_path($value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $v = trim($value);
    return $v === '' ? null : $v;
}

function ensure_dir(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException("Failed to create directory: {$path}");
    }
}

function run_cmd_json(string $cmd): array
{
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    $text = trim(implode("\n", $out));
    $json = null;
    if ($text !== '') {
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            $json = $decoded;
        }
    }
    return [$code, $text, $json];
}

function to_rel(string $base, string $path): string
{
    $base = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (str_starts_with($path, $base)) {
        return substr($path, strlen($base));
    }
    return $path;
}

function create_zip(string $sourceDir, string $zipPath): void
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Failed to open zip: {$zipPath}");
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            $abs = (string) $file->getPathname();
            $rel = to_rel($sourceDir, $abs);
            if ($file->isDir()) {
                $zip->addEmptyDir($rel);
            } else {
                $zip->addFile($abs, $rel);
            }
        }
        $zip->close();
        return;
    }

    $cmd = 'cd ' . escapeshellarg($sourceDir) . ' && zip -r ' . escapeshellarg($zipPath) . ' .';
    exec($cmd . ' >/dev/null 2>&1', $o, $code);
    if ($code !== 0) {
        throw new RuntimeException('ZipArchive unavailable and shell zip failed');
    }
}

function build_summary_markdown(array $summary): string
{
    $lines = [];
    $lines[] = '# Delivery Pipeline Summary';
    $lines[] = '';
    $lines[] = '- Status: `' . ($summary['ok'] ? 'PASS' : 'FAIL') . '`';
    $lines[] = '- Created at: `' . $summary['created_at'] . '`';
    $lines[] = '- SEO guard enabled: `' . ($summary['with_seo_guard'] ? 'yes' : 'no') . '`';
    $lines[] = '- Allow SEO critical: `' . ($summary['allow_seo_critical'] ? 'yes' : 'no') . '`';
    $lines[] = '- Package: `' . $summary['package'] . '`';
    $lines[] = '';
    $lines[] = '## Dataset Results';
    foreach ($summary['datasets'] as $k => $d) {
        $lines[] = '- `' . $k . '` => `' . ($d['ok'] ? 'PASS' : 'FAIL') . '`';
        $lines[] = '  - Input: `' . $d['input'] . '`';
        $lines[] = '  - Contract: `' . ($d['contract_ok'] ? 'PASS' : 'FAIL') . '` (errors: ' . $d['contract_errors'] . ')';
        if ($d['seo_enabled']) {
            $lines[] = '  - SEO Critical: `' . $d['seo_critical'] . '`';
            $lines[] = '  - SEO Warnings: `' . $d['seo_warning'] . '`';
            $lines[] = '  - Reports: `' . $d['contract_report'] . '`, `' . $d['seo_report'] . '`';
        } else {
            $lines[] = '  - SEO Guard: `skipped`';
            $lines[] = '  - Reports: `' . $d['contract_report'] . '`';
        }
    }
    $lines[] = '';
    $lines[] = '## Gate';
    if ($summary['ok']) {
        $lines[] = '- Ready for import.';
    } else {
        $lines[] = '- Blocked. Fix failed checks, rerun pipeline.';
    }
    $lines[] = '';
    return implode("\n", $lines);
}

$options = getopt('', [
    'category-csv:',
    'detail-csv:',
    'category-acf:',
    'detail-acf:',
    'out-dir:',
    'package-name:',
    'with-seo-guard',
    'allow-seo-critical',
    'json',
]);

$categoryCsv = normalize_opt_path($options['category-csv'] ?? null);
$detailCsv = normalize_opt_path($options['detail-csv'] ?? null);
$categoryAcf = normalize_opt_path($options['category-acf'] ?? null);
$detailAcf = normalize_opt_path($options['detail-acf'] ?? null);
$outDirOpt = normalize_opt_path($options['out-dir'] ?? null);
$packageName = normalize_opt_path($options['package-name'] ?? null) ?: 'delivery-package.zip';
$withSeoGuard = array_key_exists('with-seo-guard', $options);
$allowSeoCritical = array_key_exists('allow-seo-critical', $options);
$jsonOut = array_key_exists('json', $options);

if ($categoryCsv === null && $detailCsv === null) {
    usage();
    exit(1);
}

$datasets = [];
if ($categoryCsv !== null) {
    $datasets['category'] = ['csv' => $categoryCsv, 'acf' => $categoryAcf];
}
if ($detailCsv !== null) {
    $datasets['detail'] = ['csv' => $detailCsv, 'acf' => $detailAcf];
}

foreach ($datasets as $name => $cfg) {
    if (!is_file($cfg['csv'])) {
        fwrite(STDERR, strtoupper($name) . " CSV not found: {$cfg['csv']}\n");
        exit(1);
    }
    if ($cfg['acf'] !== null) {
        foreach (array_values(array_filter(array_map('trim', explode(',', $cfg['acf'])))) as $acfPath) {
            if (!is_file($acfPath)) {
                fwrite(STDERR, strtoupper($name) . " ACF JSON not found: {$acfPath}\n");
                exit(1);
            }
        }
    }
}

$defaultOut = getcwd() . '/output/delivery-pipeline-' . date('Ymd-His');
$outDir = $outDirOpt ?? $defaultOut;
ensure_dir($outDir);

$scriptDir = __DIR__;
$contractScript = $scriptDir . '/check_import_contract.php';
$seoScript = $scriptDir . '/seo_fact_guard.php';

$summary = [
    'ok' => true,
    'created_at' => date('c'),
    'with_seo_guard' => $withSeoGuard,
    'allow_seo_critical' => $allowSeoCritical,
    'out_dir' => $outDir,
    'package' => '',
    'datasets' => [],
];

foreach ($datasets as $name => $cfg) {
    $datasetDir = $outDir . '/' . $name;
    ensure_dir($datasetDir);

    $inputCopy = $datasetDir . '/' . $name . '-input.csv';
    copy($cfg['csv'], $inputCopy);

    $contractReport = $datasetDir . '/contract-check.md';
    $seoReport = $datasetDir . '/seo-fact-guard.md';
    $finalCsv = $datasetDir . '/' . $name . '-final.csv';
    copy($cfg['csv'], $finalCsv);

    $contractCmd = 'php ' . escapeshellarg($contractScript)
        . ' --csv ' . escapeshellarg($cfg['csv'])
        . ' --must-have ' . escapeshellarg('ID,slug')
        . ' --report ' . escapeshellarg($contractReport)
        . ' --json';
    if ($cfg['acf'] !== null) {
        $contractCmd .= ' --acf ' . escapeshellarg($cfg['acf']);
    }

    [$contractCode, $contractRaw, $contractJson] = run_cmd_json($contractCmd);
    if (!is_array($contractJson)) {
        $contractJson = [
            'ok' => false,
            'errors' => ["Contract checker returned non-JSON output (code {$contractCode})."],
            'warnings' => [],
        ];
    }

    $seoCode = 0;
    $seoRaw = '';
    $seoJson = [
        'ok' => true,
        'summary' => ['critical' => 0, 'warning' => 0],
        'findings' => [],
        'skipped' => !$withSeoGuard,
    ];
    if ($withSeoGuard) {
        $seoCmd = 'php ' . escapeshellarg($seoScript)
            . ' --csv ' . escapeshellarg($cfg['csv'])
            . ' --report ' . escapeshellarg($seoReport)
            . ' --json';
        [$seoCode, $seoRaw, $seoJson] = run_cmd_json($seoCmd);
        if (!is_array($seoJson)) {
            $seoJson = [
                'ok' => false,
                'summary' => ['critical' => 1, 'warning' => 0],
                'findings' => [
                    ['severity' => 'critical', 'rule' => 'SEO_GUARD_RUNTIME', 'message' => "SEO guard returned non-JSON output (code {$seoCode})."],
                ],
            ];
        }
    }

    $contractOk = ($contractCode === 0) && (($contractJson['ok'] ?? false) === true);
    $seoCritical = (int) (($seoJson['summary']['critical'] ?? 0));
    $seoWarning = (int) (($seoJson['summary']['warning'] ?? 0));
    $seoPass = !$withSeoGuard ? true : ($allowSeoCritical ? true : ($seoCritical === 0));

    $datasetOk = $contractOk && $seoPass;
    if (!$datasetOk) {
        $summary['ok'] = false;
    }

    $summary['datasets'][$name] = [
        'ok' => $datasetOk,
        'input' => to_rel($outDir, $inputCopy),
        'final_csv' => to_rel($outDir, $finalCsv),
        'contract_ok' => $contractOk,
        'contract_errors' => count((array) ($contractJson['errors'] ?? [])),
        'contract_warnings' => count((array) ($contractJson['warnings'] ?? [])),
        'contract_report' => to_rel($outDir, $contractReport),
        'seo_enabled' => $withSeoGuard,
        'seo_ok' => $seoCritical === 0,
        'seo_critical' => $seoCritical,
        'seo_warning' => $seoWarning,
        'seo_report' => $withSeoGuard ? to_rel($outDir, $seoReport) : 'skipped',
        'raw' => [
            'contract_exit_code' => $contractCode,
            'contract_stdout' => $contractRaw,
            'seo_exit_code' => $seoCode,
            'seo_stdout' => $seoRaw,
        ],
    ];

    file_put_contents($datasetDir . '/contract-check.json', json_encode($contractJson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    file_put_contents($datasetDir . '/seo-fact-guard.json', json_encode($seoJson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

$manifestPath = $outDir . '/manifest.json';
$summaryPath = $outDir . '/summary.md';
file_put_contents($manifestPath, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents($summaryPath, build_summary_markdown($summary));

$zipPath = $outDir . '/' . $packageName;
create_zip($outDir, $zipPath);
$summary['package'] = to_rel($outDir, $zipPath);
file_put_contents($manifestPath, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents($summaryPath, build_summary_markdown($summary));

if ($jsonOut) {
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
} else {
    fwrite(STDOUT, build_summary_markdown($summary));
}

exit($summary['ok'] ? 0 : 2);
