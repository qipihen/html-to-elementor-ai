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
  php seo_fact_guard.php --csv <file.csv> [--report <report.md>] [--json]

Purpose:
  Run high-signal SEO/fact checks on category/detail CSV before import.

Exit code:
  0 = no critical findings
  2 = critical findings detected
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

function normalize_space(string $v): string
{
    $v = preg_replace('/\s+/u', ' ', trim($v));
    return is_string($v) ? $v : '';
}

function load_csv_rows(string $path): array
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        throw new RuntimeException("Failed to open CSV: {$path}");
    }

    $header = fgetcsv($fh, 0, ',', '"', '\\');
    if ($header === false || !is_array($header)) {
        fclose($fh);
        throw new RuntimeException('CSV header is empty');
    }
    $header = array_map(static fn($h) => normalize_header((string) $h), $header);

    $rows = [];
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $assoc = [];
        foreach ($header as $i => $h) {
            $assoc[$h] = (string) ($row[$i] ?? '');
        }
        $rows[] = $assoc;
    }
    fclose($fh);

    return [$header, $rows];
}

function add_finding(array &$out, string $severity, int $rowNo, string $slug, string $rule, string $msg): void
{
    $out[] = [
        'severity' => $severity,
        'row' => $rowNo,
        'slug' => $slug,
        'rule' => $rule,
        'message' => $msg,
    ];
}

function render_report(array $result): string
{
    $lines = [];
    $lines[] = "# SEO & Fact Guard Report";
    $lines[] = "";
    $lines[] = "- CSV: `{$result['csv']}`";
    $lines[] = "- Rows checked: `{$result['rows_checked']}`";
    $lines[] = "- Critical: `{$result['summary']['critical']}`";
    $lines[] = "- Warning: `{$result['summary']['warning']}`";
    $lines[] = "- Status: `" . ($result['ok'] ? 'PASS' : 'CRITICAL_FOUND') . "`";
    $lines[] = "";
    $lines[] = "## Findings";
    if (count($result['findings']) === 0) {
        $lines[] = "- none";
    } else {
        foreach ($result['findings'] as $f) {
            $lines[] = "- [{$f['severity']}] row {$f['row']} slug `{$f['slug']}` {$f['rule']}: {$f['message']}";
        }
    }
    $lines[] = "";
    return implode("\n", $lines) . "\n";
}

$options = getopt('', ['csv:', 'report:', 'json']);
$csv = $options['csv'] ?? null;
$reportPath = $options['report'] ?? null;
$jsonOut = array_key_exists('json', $options);

if ($csv === null) {
    usage();
    exit(1);
}
if (!is_file($csv)) {
    fwrite(STDERR, "CSV not found: {$csv}\n");
    exit(1);
}

[$headers, $rows] = load_csv_rows($csv);

$findings = [];
$globalPhrase = normalize_space(strtolower(
    'Engineered to meet rigorous IEC 60502 and harmonized European standards. Suitable for critical infrastructure projects in Europe, the Middle East, and global markets requiring strict compliance and safety.'
));

$titleCols = array_values(array_filter($headers, static fn($h) => preg_match('/title/i', $h) === 1));
$metaDescCols = array_values(array_filter($headers, static fn($h) => preg_match('/meta.*description|description.*meta|meta_description/i', $h) === 1));

foreach ($rows as $i => $row) {
    $rowNo = $i + 2;
    $slug = trim((string) ($row['slug'] ?? $row['Slug'] ?? $row['post_name'] ?? ''));
    $id = trim((string) ($row['ID'] ?? ''));
    if ($slug === '' && $id !== '') {
        $slug = "id-{$id}";
    }
    if ($slug === '') {
        $slug = "row-{$rowNo}";
    }

    $rowText = strtolower(normalize_space(implode(' ', $row)));

    if (str_contains($rowText, $globalPhrase)) {
        add_finding($findings, 'critical', $rowNo, $slug, 'GLOBAL_TEXT_POLLUTION', 'Detected duplicated global sentence likely copied across unrelated pages.');
    }

    if (str_contains($rowText, 'btly') && str_contains($rowText, 'aluminum conductor')) {
        add_finding($findings, 'critical', $rowNo, $slug, 'BTLY_CONDUCTOR_CONFLICT', 'BTLY row contains "aluminum conductor"; fire-resistant mineral cable should be validated as copper-based.');
    }

    if (str_contains($rowText, '1.8/3kv') && str_contains($rowText, 'iec 60502-2')) {
        add_finding($findings, 'critical', $rowNo, $slug, 'IEC_RANGE_MISMATCH', '1.8/3kV row references IEC 60502-2; verify if IEC 60502-1 should be used.');
    }

    if (str_contains($rowText, 'pv1-f') && str_contains($rowText, 'en 50618')) {
        add_finding($findings, 'warning', $rowNo, $slug, 'PV_STANDARD_MIX', 'PV1-F and EN 50618 appear together; verify model-to-standard mapping.');
    }

    if (str_contains($rowText, 'h07z-k') && !str_contains($rowText, 'bs 7211')) {
        add_finding($findings, 'warning', $rowNo, $slug, 'H07ZK_STANDARD_MISSING', 'H07Z-K detected without BS 7211 mention.');
    }

    if (str_contains($rowText, 'cy-ay')) {
        add_finding($findings, 'warning', $rowNo, $slug, 'MODEL_NAMING_VERIFY', 'CY-AY detected; verify naming and market compatibility.');
    }

    foreach ($metaDescCols as $col) {
        $val = (string) ($row[$col] ?? '');
        if ($val !== '' && (str_contains($val, '...') || str_contains($val, '…'))) {
            add_finding($findings, 'warning', $rowNo, $slug, 'META_TRUNCATION', "Meta description appears truncated in column {$col}.");
        }
    }

    foreach ($titleCols as $col) {
        $title = strtolower((string) ($row[$col] ?? ''));
        if ($title === '') {
            continue;
        }

        $isLvSlug = preg_match('/low[-_ ]?voltage|(^|-)lv($|-)/i', $slug) === 1;
        if (str_contains($title, 'low voltage cable') && !$isLvSlug) {
            add_finding($findings, 'critical', $rowNo, $slug, 'TITLE_SUFFIX_POLLUTION', "Title contains 'low voltage cable' but slug is not low-voltage scoped ({$col}).");
        }

        $isDetailLike = preg_match('/\d+\s*(mm|mm²|kv)|\d+x\d+/i', $title) === 1;
        if ($isDetailLike && (str_contains($title, 'manufacturer') || str_contains($title, 'supplier'))) {
            add_finding($findings, 'warning', $rowNo, $slug, 'INTENT_CANNIBALIZATION', "Detail-like title includes manufacturer/supplier intent ({$col}); verify category/detail keyword split.");
        }
    }
}

$critical = count(array_filter($findings, static fn($f) => $f['severity'] === 'critical'));
$warning = count(array_filter($findings, static fn($f) => $f['severity'] === 'warning'));

$result = [
    'ok' => $critical === 0,
    'csv' => realpath($csv) ?: $csv,
    'rows_checked' => count($rows),
    'summary' => [
        'critical' => $critical,
        'warning' => $warning,
    ],
    'findings' => $findings,
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

exit($critical === 0 ? 0 : 2);
