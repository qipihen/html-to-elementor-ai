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
  php check_import_contract.php --csv <file.csv> [--acf <fields.json[,fields2.json]>] [--must-have <col1,col2>] [--report <report.md>] [--json]

Purpose:
  Validate CSV import contract before WordPress bulk import:
  - file format sanity (detect xlsx/zip upload by mistake)
  - required headers (default includes ID)
  - optional ACF field-name alignment check

Exit code:
  0 = pass (warnings allowed)
  2 = failed (contract-breaking errors)
TXT;
    fwrite(STDOUT, $msg . "\n");
}

function normalize_header(string $v): string
{
    $v = trim($v);
    if (str_starts_with($v, "\xEF\xBB\xBF")) {
        $v = substr($v, 3);
    }
    return $v;
}

function read_csv_header(string $csvPath): array
{
    $fh = fopen($csvPath, 'rb');
    if ($fh === false) {
        throw new RuntimeException("Failed to open CSV: {$csvPath}");
    }

    $header = fgetcsv($fh, 0, ',', '"', '\\');
    fclose($fh);
    if ($header === false || !is_array($header)) {
        throw new RuntimeException('CSV header row is empty or unreadable');
    }

    return array_map(static fn($h) => normalize_header((string) $h), $header);
}

function count_csv_rows(string $csvPath): int
{
    $count = 0;
    $fh = fopen($csvPath, 'rb');
    if ($fh === false) {
        return 0;
    }
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if ($count === 0) {
            $count++;
            continue;
        }
        $isEmpty = true;
        foreach ((array) $row as $cell) {
            if (trim((string) $cell) !== '') {
                $isEmpty = false;
                break;
            }
        }
        if (!$isEmpty) {
            $count++;
        }
    }
    fclose($fh);
    return max(0, $count - 1);
}

function collect_acf_field_names($node, array &$set): void
{
    if (!is_array($node)) {
        return;
    }

    $isList = array_keys($node) === range(0, count($node) - 1);
    if ($isList) {
        foreach ($node as $item) {
            collect_acf_field_names($item, $set);
        }
        return;
    }

    if (isset($node['name']) && is_string($node['name'])) {
        $name = trim($node['name']);
        if ($name !== '') {
            $set[$name] = true;
        }
    }

    foreach (['fields', 'sub_fields', 'layouts'] as $k) {
        if (isset($node[$k])) {
            collect_acf_field_names($node[$k], $set);
        }
    }
}

function render_report(array $result): string
{
    $lines = [];
    $lines[] = "# Import Contract Check";
    $lines[] = "";
    $lines[] = "- CSV: `{$result['csv']}`";
    $lines[] = "- Data rows: `{$result['stats']['rows']}`";
    $lines[] = "- Header count: `{$result['stats']['headers']}`";
    $lines[] = "- Status: `" . ($result['ok'] ? 'PASS' : 'FAIL') . "`";
    $lines[] = "";

    $lines[] = "## Headers";
    foreach ($result['header'] as $h) {
        $lines[] = "- `{$h}`";
    }
    $lines[] = "";

    $lines[] = "## Errors";
    if (count($result['errors']) === 0) {
        $lines[] = "- none";
    } else {
        foreach ($result['errors'] as $e) {
            $lines[] = "- {$e}";
        }
    }
    $lines[] = "";

    $lines[] = "## Warnings";
    if (count($result['warnings']) === 0) {
        $lines[] = "- none";
    } else {
        foreach ($result['warnings'] as $w) {
            $lines[] = "- {$w}";
        }
    }
    $lines[] = "";

    return implode("\n", $lines) . "\n";
}

$options = getopt('', [
    'csv:',
    'acf:',
    'must-have:',
    'report:',
    'json',
]);

$csvPath = $options['csv'] ?? null;
$acfListRaw = $options['acf'] ?? null;
$mustHaveRaw = $options['must-have'] ?? null;
$reportPath = $options['report'] ?? null;
$jsonOut = array_key_exists('json', $options);

if ($csvPath === null) {
    usage();
    exit(1);
}
if (!is_file($csvPath)) {
    fwrite(STDERR, "CSV not found: {$csvPath}\n");
    exit(1);
}

$errors = [];
$warnings = [];

$first4 = file_get_contents($csvPath, false, null, 0, 4);
if ($first4 !== false && str_starts_with($first4, "PK\x03\x04")) {
    $errors[] = "File looks like ZIP/XLSX (starts with PK\\x03\\x04), not plain CSV.";
}

try {
    $header = read_csv_header($csvPath);
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
    $header = [];
}

if (count($header) <= 1 && count($errors) === 0) {
    $warnings[] = 'CSV appears to have only one column. Check delimiter and file encoding.';
}

$mustHave = ['ID'];
if ($mustHaveRaw !== null && trim((string) $mustHaveRaw) !== '') {
    $mustHave = array_values(array_filter(array_map('trim', explode(',', (string) $mustHaveRaw))));
}

$headerSet = [];
foreach ($header as $h) {
    $headerSet[$h] = true;
}

foreach ($mustHave as $col) {
    if (!isset($headerSet[$col])) {
        $errors[] = "Missing required header: {$col}";
    }
}

if (!isset($headerSet['ID']) && isset($headerSet['id'])) {
    $warnings[] = "Header uses 'id' but plugin import typically expects 'ID'.";
}

$acfNames = [];
if ($acfListRaw !== null && trim((string) $acfListRaw) !== '') {
    $acfPaths = array_values(array_filter(array_map('trim', explode(',', (string) $acfListRaw))));
    foreach ($acfPaths as $acfPath) {
        if (!is_file($acfPath)) {
            $errors[] = "ACF JSON not found: {$acfPath}";
            continue;
        }
        $raw = file_get_contents($acfPath);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $errors[] = "Invalid ACF JSON: {$acfPath}";
            continue;
        }
        collect_acf_field_names($decoded, $acfNames);
    }

    if (count($acfNames) > 0) {
        $coreCols = [
            'ID' => true, 'Name' => true, 'Slug' => true, 'Description' => true,
            'Parent' => true, 'meta_title' => true, 'meta_description' => true,
            'title' => true, 'description' => true, 'slug' => true,
        ];
        foreach ($header as $col) {
            if (isset($coreCols[$col])) {
                continue;
            }
            if (!isset($acfNames[$col])) {
                $warnings[] = "Header not found in provided ACF fields: {$col}";
            }
        }
    }
}

$result = [
    'ok' => count($errors) === 0,
    'csv' => realpath($csvPath) ?: $csvPath,
    'header' => $header,
    'errors' => $errors,
    'warnings' => $warnings,
    'stats' => [
        'headers' => count($header),
        'rows' => count_csv_rows($csvPath),
        'acf_fields_loaded' => count($acfNames),
    ],
];

$report = render_report($result);
if ($reportPath !== null && trim((string) $reportPath) !== '') {
    file_put_contents($reportPath, $report);
}

if ($jsonOut) {
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
} else {
    fwrite(STDOUT, $report);
}

exit($result['ok'] ? 0 : 2);
