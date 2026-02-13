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
  php validate_elementor_json.php --input <file.json> [--strict] [--report <report.json>]

Options:
  --input   Elementor JSON file path
  --strict  Enable stricter type and element checks
  --report  Optional path to save validation report JSON
TXT;
    fwrite(STDOUT, $msg . "\n");
}

function is_list_array(array $arr): bool
{
    if ($arr === []) {
        return true;
    }
    return array_keys($arr) === range(0, count($arr) - 1);
}

function &null_ref()
{
    static $null = null;
    return $null;
}

function &resolve_root_elements_ref(array &$data, string &$rootMode)
{
    if (is_list_array($data)) {
        $rootMode = 'list-root';
        return $data;
    }

    if (isset($data['content']) && is_array($data['content'])) {
        $rootMode = 'page-content';
        return $data['content'];
    }

    if (isset($data['elements']) && is_array($data['elements'])) {
        $rootMode = 'single-node-elements';
        return $data['elements'];
    }

    $rootMode = 'unknown';
    $null =& null_ref();
    return $null;
}

function add_error(array &$errors, string $path, string $msg): void
{
    $errors[] = ['path' => $path, 'message' => $msg];
}

function add_warning(array &$warnings, string $path, string $msg): void
{
    $warnings[] = ['path' => $path, 'message' => $msg];
}

function validate_node(
    $node,
    string $path,
    array &$idSet,
    array &$errors,
    array &$warnings,
    array &$stats,
    bool $strict
): void {
    if (!is_array($node) || is_list_array($node)) {
        add_error($errors, $path, 'Node must be an object-like array');
        return;
    }

    foreach (['id', 'elType', 'settings', 'elements'] as $key) {
        if (!array_key_exists($key, $node)) {
            add_error($errors, $path, "Missing required key '{$key}'");
        }
    }

    if (isset($node['id'])) {
        if (!is_string($node['id']) || trim($node['id']) === '') {
            add_error($errors, $path . '/id', 'id must be a non-empty string');
        } else {
            if (isset($idSet[$node['id']])) {
                add_error($errors, $path . '/id', "Duplicate id '{$node['id']}'");
            }
            $idSet[$node['id']] = true;
        }
    }

    $elType = $node['elType'] ?? null;
    if (isset($node['elType']) && !is_string($node['elType'])) {
        add_error($errors, $path . '/elType', 'elType must be a string');
    } elseif (is_string($elType)) {
        $stats['by_el_type'][$elType] = ($stats['by_el_type'][$elType] ?? 0) + 1;
    }

    if (isset($node['settings']) && !is_array($node['settings'])) {
        add_error($errors, $path . '/settings', 'settings must be an array/object');
    }

    if (isset($node['elements'])) {
        if (!is_array($node['elements'])) {
            add_error($errors, $path . '/elements', 'elements must be an array');
        } elseif (!is_list_array($node['elements'])) {
            add_warning($warnings, $path . '/elements', 'elements is not a list array; verify structure');
        }
    }

    if ($elType === 'widget') {
        if (!isset($node['widgetType']) || !is_string($node['widgetType']) || trim($node['widgetType']) === '') {
            add_error($errors, $path . '/widgetType', 'widget node requires non-empty widgetType');
        }
    }

    if ($strict && is_string($elType)) {
        $knownTypes = ['container', 'widget', 'section', 'column'];
        if (!in_array($elType, $knownTypes, true)) {
            add_warning($warnings, $path . '/elType', "Unknown elType '{$elType}'");
        }
    }

    if (isset($node['isInner']) && !is_bool($node['isInner']) && !in_array($node['isInner'], [0, 1, '0', '1'], true)) {
        add_warning($warnings, $path . '/isInner', 'isInner is non-standard type; verify Elementor compatibility');
    }

    $stats['nodes_total']++;

    if (isset($node['elements']) && is_array($node['elements'])) {
        foreach ($node['elements'] as $idx => $child) {
            validate_node($child, $path . '/elements/' . $idx, $idSet, $errors, $warnings, $stats, $strict);
        }
    }
}

$options = getopt('', ['input:', 'strict', 'report:']);

$input = $options['input'] ?? null;
if ($input === null) {
    // fallback: first positional arg
    global $argv;
    $input = $argv[1] ?? null;
}

if ($input === null) {
    usage();
    exit(1);
}

$strict = array_key_exists('strict', $options);
$reportPath = $options['report'] ?? null;

if (!is_file($input)) {
    fwrite(STDERR, "Input file not found: {$input}\n");
    exit(1);
}

$json = file_get_contents($input);
if ($json === false) {
    fwrite(STDERR, "Failed to read input file: {$input}\n");
    exit(1);
}

$data = json_decode($json, true);
if (!is_array($data)) {
    fwrite(STDERR, "Invalid JSON or root is not array/object.\n");
    exit(1);
}

$rootMode = '';
$elements =& resolve_root_elements_ref($data, $rootMode);
if (!is_array($elements)) {
    fwrite(STDERR, "Could not resolve root elements list. Expecting list root, content[], or elements[].\n");
    exit(2);
}

$errors = [];
$warnings = [];
$idSet = [];
$stats = [
    'root_mode' => $rootMode,
    'nodes_total' => 0,
    'by_el_type' => [],
    'strict' => $strict,
    'input' => realpath($input) ?: $input,
];

foreach ($elements as $idx => $node) {
    validate_node($node, '/root/' . $idx, $idSet, $errors, $warnings, $stats, $strict);
}

$report = [
    'ok' => count($errors) === 0,
    'errors' => $errors,
    'warnings' => $warnings,
    'stats' => $stats,
];

if ($reportPath !== null) {
    file_put_contents($reportPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
exit($report['ok'] ? 0 : 2);
