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
  php apply_elementor_patch.php --input <page.json> --patch <ops.json> [--output <patched.json>] [--log <changes.json>] [--allow-partial] [--dry-run]

Options:
  --allow-partial   Continue processing even when one op fails. Output includes successful ops.
  --dry-run         Do not write output file; print/report patch result only.
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

function &resolve_root_elements_ref(array &$data, string &$mode)
{
    if (is_list_array($data)) {
        $mode = 'list-root';
        return $data;
    }

    if (isset($data['content']) && is_array($data['content'])) {
        $mode = 'page-content';
        return $data['content'];
    }

    if (isset($data['elements']) && is_array($data['elements'])) {
        $mode = 'single-node-elements';
        return $data['elements'];
    }

    $mode = 'unknown';
    $null =& null_ref();
    return $null;
}

function find_index_path_by_id(array $elements, string $targetId, array $prefix = []): ?array
{
    foreach ($elements as $i => $node) {
        if (!is_array($node)) {
            continue;
        }

        $path = array_merge($prefix, [$i]);
        if (($node['id'] ?? null) === $targetId) {
            return $path;
        }

        if (isset($node['elements']) && is_array($node['elements'])) {
            $found = find_index_path_by_id($node['elements'], $targetId, $path);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

function path_is_prefix(array $prefix, array $path): bool
{
    if (count($prefix) > count($path)) {
        return false;
    }

    foreach ($prefix as $i => $v) {
        if (!array_key_exists($i, $path) || $path[$i] !== $v) {
            return false;
        }
    }

    return true;
}

function &get_node_ref_by_index_path(array &$elements, array $path)
{
    if ($path === []) {
        $null =& null_ref();
        return $null;
    }

    $ref =& $elements;
    $depth = count($path);
    foreach ($path as $level => $idx) {
        if (!isset($ref[$idx])) {
            $null =& null_ref();
            return $null;
        }
        $ref =& $ref[$idx];
        if ($level < $depth - 1) {
            if (!isset($ref['elements']) || !is_array($ref['elements'])) {
                $null =& null_ref();
                return $null;
            }
            $ref =& $ref['elements'];
        }
    }

    return $ref;
}

function &get_parent_elements_ref_by_index_path(array &$elements, array $path)
{
    if (count($path) <= 1) {
        return $elements;
    }

    $ref =& $elements;
    $max = count($path) - 1;
    for ($i = 0; $i < $max; $i++) {
        $idx = $path[$i];
        if (!isset($ref[$idx]) || !is_array($ref[$idx])) {
            $null =& null_ref();
            return $null;
        }
        if (!isset($ref[$idx]['elements']) || !is_array($ref[$idx]['elements'])) {
            $ref[$idx]['elements'] = [];
        }
        $ref =& $ref[$idx]['elements'];
    }

    return $ref;
}

function path_to_string(array $path): string
{
    if ($path === []) {
        return '/root';
    }

    $parts = ['/root'];
    $depth = count($path);
    foreach ($path as $i => $idx) {
        $parts[] = '/' . $idx;
        if ($i < $depth - 1) {
            $parts[] = '/elements';
        }
    }
    return implode('', $parts);
}

function ensure_element_shape(array &$node): void
{
    if (!isset($node['id']) || !is_string($node['id']) || trim($node['id']) === '') {
        $node['id'] = bin2hex(random_bytes(4));
    }
    if (!isset($node['elType']) || !is_string($node['elType'])) {
        $node['elType'] = 'container';
    }
    if (!isset($node['settings']) || !is_array($node['settings'])) {
        $node['settings'] = [];
    }
    if (!isset($node['elements']) || !is_array($node['elements'])) {
        $node['elements'] = [];
    }
}

function collect_ids(array $elements, array &$idSet): void
{
    foreach ($elements as $node) {
        if (!is_array($node)) {
            continue;
        }
        if (isset($node['id']) && is_string($node['id']) && $node['id'] !== '') {
            $idSet[$node['id']] = true;
        }
        if (isset($node['elements']) && is_array($node['elements'])) {
            collect_ids($node['elements'], $idSet);
        }
    }
}

function generate_unique_id(array &$idSet): string
{
    do {
        $id = bin2hex(random_bytes(4));
    } while (isset($idSet[$id]));
    $idSet[$id] = true;
    return $id;
}

function clone_with_new_ids(array $node, array &$idSet): array
{
    $node['id'] = generate_unique_id($idSet);
    if (!isset($node['settings']) || !is_array($node['settings'])) {
        $node['settings'] = [];
    }

    if (isset($node['elements']) && is_array($node['elements'])) {
        $children = [];
        foreach ($node['elements'] as $child) {
            if (is_array($child)) {
                $children[] = clone_with_new_ids($child, $idSet);
            }
        }
        $node['elements'] = $children;
    } else {
        $node['elements'] = [];
    }

    return $node;
}

function set_by_dot_path(array &$arr, string $path, $value): void
{
    $segments = array_values(array_filter(explode('.', $path), static fn($s) => $s !== ''));
    if ($segments === []) {
        return;
    }

    $cursor =& $arr;
    $last = array_pop($segments);
    foreach ($segments as $seg) {
        if (!isset($cursor[$seg]) || !is_array($cursor[$seg])) {
            $cursor[$seg] = [];
        }
        $cursor =& $cursor[$seg];
    }
    $cursor[$last] = $value;
}

function get_by_dot_path(array $arr, string $path, bool &$exists)
{
    $segments = array_values(array_filter(explode('.', $path), static fn($s) => $s !== ''));
    if ($segments === []) {
        $exists = true;
        return $arr;
    }

    $cursor = $arr;
    foreach ($segments as $seg) {
        if (!is_array($cursor) || !array_key_exists($seg, $cursor)) {
            $exists = false;
            return null;
        }
        $cursor = $cursor[$seg];
    }

    $exists = true;
    return $cursor;
}

function insert_at_position(array &$elements, array $node, $position): int
{
    $count = count($elements);

    if ($position === 'start') {
        array_unshift($elements, $node);
        return 0;
    }

    if ($position === 'end' || $position === null || $position === '') {
        $elements[] = $node;
        return count($elements) - 1;
    }

    if (is_int($position) || ctype_digit((string) $position)) {
        $idx = (int) $position;
        if ($idx < 0) {
            $idx = 0;
        }
        if ($idx > $count) {
            $idx = $count;
        }
        array_splice($elements, $idx, 0, [$node]);
        return $idx;
    }

    $elements[] = $node;
    return count($elements) - 1;
}

$options = getopt('', ['input:', 'patch:', 'output:', 'log:', 'allow-partial', 'dry-run']);
$input = $options['input'] ?? null;
$patch = $options['patch'] ?? null;
$outputRaw = $options['output'] ?? null;
$logRaw = $options['log'] ?? null;
$allowPartial = array_key_exists('allow-partial', $options);
$dryRun = array_key_exists('dry-run', $options);

$output = is_string($outputRaw) ? trim($outputRaw) : null;
$logFile = is_string($logRaw) ? trim($logRaw) : null;

if (array_key_exists('output', $options) && ($output === null || $output === '')) {
    fwrite(STDERR, "--output requires a non-empty file path.\n");
    exit(1);
}
if (array_key_exists('log', $options) && ($logFile === null || $logFile === '')) {
    fwrite(STDERR, "--log requires a non-empty file path.\n");
    exit(1);
}

if ($input === null || $patch === null) {
    usage();
    exit(1);
}
if (!$dryRun && ($output === null || $output === '')) {
    fwrite(STDERR, "--output is required unless --dry-run is used.\n");
    exit(1);
}

if (!is_file($input) || !is_file($patch)) {
    fwrite(STDERR, "Input or patch file not found.\n");
    exit(1);
}

$rawInput = file_get_contents($input);
$rawPatch = file_get_contents($patch);
if ($rawInput === false || $rawPatch === false) {
    fwrite(STDERR, "Failed to read input files.\n");
    exit(1);
}

$data = json_decode($rawInput, true);
$patchData = json_decode($rawPatch, true);
if (!is_array($data) || !is_array($patchData)) {
    fwrite(STDERR, "Invalid JSON in input or patch.\n");
    exit(1);
}

$ops = $patchData['operations'] ?? null;
if (!is_array($ops) || !is_list_array($ops)) {
    fwrite(STDERR, "Patch file must contain an operations list.\n");
    exit(1);
}

$workingData = $data;
$rootMode = '';
$rootElements =& resolve_root_elements_ref($workingData, $rootMode);
if (!is_array($rootElements)) {
    fwrite(STDERR, "Unable to resolve Elementor root elements list.\n");
    exit(2);
}

$idSet = [];
collect_ids($rootElements, $idSet);

$changeLog = [
    'input' => realpath($input) ?: $input,
    'patch' => realpath($patch) ?: $patch,
    'root_mode' => $rootMode,
    'allow_partial' => $allowPartial,
    'dry_run' => $dryRun,
    'operations' => [],
    'errors' => [],
    'aborted_on_error' => false,
    'rolled_back' => false,
    'output_written' => false,
];

foreach ($ops as $i => $op) {
    $opName = is_array($op) ? ($op['op'] ?? '') : '';
    $entry = [
        'index' => $i,
        'op' => $opName,
        'status' => 'ok',
        'message' => '',
    ];

    if (!is_array($op) || $opName === '') {
        $entry['status'] = 'error';
        $entry['message'] = 'Invalid operation format';
        $changeLog['operations'][] = $entry;
        $changeLog['errors'][] = $entry;
        if (!$allowPartial) {
            $changeLog['aborted_on_error'] = true;
            break;
        }
        continue;
    }

    try {
        switch ($opName) {
            case 'update_settings': {
                $id = (string) ($op['element_id'] ?? '');
                $settings = $op['settings'] ?? null;
                if ($id === '' || !is_array($settings)) {
                    throw new RuntimeException('update_settings requires element_id and settings object');
                }
                $path = find_index_path_by_id($rootElements, $id);
                if ($path === null) {
                    throw new RuntimeException("Element '{$id}' not found");
                }
                $node =& get_node_ref_by_index_path($rootElements, $path);
                if (!isset($node['settings']) || !is_array($node['settings'])) {
                    $node['settings'] = [];
                }
                $node['settings'] = array_replace_recursive($node['settings'], $settings);
                $entry['path'] = path_to_string($path) . '/settings';
                break;
            }

            case 'set_setting': {
                $id = (string) ($op['element_id'] ?? '');
                $pathDot = (string) ($op['path'] ?? '');
                if ($id === '' || $pathDot === '' || !array_key_exists('value', $op)) {
                    throw new RuntimeException('set_setting requires element_id, path, value');
                }
                $path = find_index_path_by_id($rootElements, $id);
                if ($path === null) {
                    throw new RuntimeException("Element '{$id}' not found");
                }
                $node =& get_node_ref_by_index_path($rootElements, $path);
                if (!isset($node['settings']) || !is_array($node['settings'])) {
                    $node['settings'] = [];
                }
                set_by_dot_path($node['settings'], $pathDot, $op['value']);
                $entry['path'] = path_to_string($path) . '/settings/' . str_replace('.', '/', $pathDot);
                break;
            }

            case 'replace_text': {
                $id = (string) ($op['element_id'] ?? '');
                $pathDot = (string) ($op['path'] ?? '');
                $find = (string) ($op['find'] ?? '');
                $replace = (string) ($op['replace'] ?? '');
                if ($id === '' || $pathDot === '') {
                    throw new RuntimeException('replace_text requires element_id and path');
                }
                $path = find_index_path_by_id($rootElements, $id);
                if ($path === null) {
                    throw new RuntimeException("Element '{$id}' not found");
                }
                $node =& get_node_ref_by_index_path($rootElements, $path);
                if (!isset($node['settings']) || !is_array($node['settings'])) {
                    throw new RuntimeException("Element '{$id}' has no settings object");
                }
                $exists = false;
                $value = get_by_dot_path($node['settings'], $pathDot, $exists);
                if (!$exists || !is_string($value)) {
                    throw new RuntimeException("replace_text target is missing or not string at '{$pathDot}'");
                }
                $newValue = str_replace($find, $replace, $value);
                set_by_dot_path($node['settings'], $pathDot, $newValue);
                $entry['path'] = path_to_string($path) . '/settings/' . str_replace('.', '/', $pathDot);
                $entry['before'] = $value;
                $entry['after'] = $newValue;
                break;
            }

            case 'remove_element': {
                $id = (string) ($op['element_id'] ?? '');
                if ($id === '') {
                    throw new RuntimeException('remove_element requires element_id');
                }
                $path = find_index_path_by_id($rootElements, $id);
                if ($path === null) {
                    throw new RuntimeException("Element '{$id}' not found");
                }
                $parent =& get_parent_elements_ref_by_index_path($rootElements, $path);
                $idx = end($path);
                unset($parent[$idx]);
                $parent = array_values($parent);
                unset($idSet[$id]);
                $entry['path'] = path_to_string($path);
                break;
            }

            case 'move_element': {
                $id = (string) ($op['element_id'] ?? '');
                $newParentId = (string) ($op['new_parent_id'] ?? '__root__');
                $position = $op['position'] ?? 'end';
                if ($id === '') {
                    throw new RuntimeException('move_element requires element_id');
                }

                $path = find_index_path_by_id($rootElements, $id);
                if ($path === null) {
                    throw new RuntimeException("Element '{$id}' not found");
                }
                $fromPath = path_to_string($path);

                // Validate destination BEFORE removing source.
                if (!($newParentId === '__root__' || $newParentId === '')) {
                    $preDestPath = find_index_path_by_id($rootElements, $newParentId);
                    if ($preDestPath === null) {
                        throw new RuntimeException("New parent '{$newParentId}' not found");
                    }
                    if (path_is_prefix($path, $preDestPath)) {
                        throw new RuntimeException('Cannot move element into itself or its descendant');
                    }
                }

                $node =& get_node_ref_by_index_path($rootElements, $path);
                $moving = $node;

                $parent =& get_parent_elements_ref_by_index_path($rootElements, $path);
                $idx = end($path);
                unset($parent[$idx]);
                $parent = array_values($parent);

                if ($newParentId === '__root__' || $newParentId === '') {
                    $dest =& $rootElements;
                } else {
                    // Re-resolve in updated tree to avoid stale index-path references.
                    $pPath = find_index_path_by_id($rootElements, $newParentId);
                    if ($pPath === null) {
                        throw new RuntimeException("New parent '{$newParentId}' not found after source removal");
                    }
                    $pNode =& get_node_ref_by_index_path($rootElements, $pPath);
                    if (!isset($pNode['elements']) || !is_array($pNode['elements'])) {
                        $pNode['elements'] = [];
                    }
                    $dest =& $pNode['elements'];
                }

                $newIdx = insert_at_position($dest, $moving, $position);
                $entry['from'] = $fromPath;
                $entry['to_index'] = $newIdx;
                break;
            }

            case 'duplicate_element': {
                $id = (string) ($op['element_id'] ?? '');
                $newParentId = (string) ($op['new_parent_id'] ?? '__same_parent__');
                $position = $op['position'] ?? 'after';
                if ($id === '') {
                    throw new RuntimeException('duplicate_element requires element_id');
                }

                $srcPath = find_index_path_by_id($rootElements, $id);
                if ($srcPath === null) {
                    throw new RuntimeException("Element '{$id}' not found");
                }
                $srcNode =& get_node_ref_by_index_path($rootElements, $srcPath);
                $copy = clone_with_new_ids($srcNode, $idSet);

                if ($newParentId === '__same_parent__') {
                    $srcParent =& get_parent_elements_ref_by_index_path($rootElements, $srcPath);
                    $srcIdx = end($srcPath);
                    if ($position === 'after') {
                        array_splice($srcParent, $srcIdx + 1, 0, [$copy]);
                        $newIdx = $srcIdx + 1;
                    } else {
                        $newIdx = insert_at_position($srcParent, $copy, $position);
                    }
                } elseif ($newParentId === '__root__' || $newParentId === '') {
                    $newIdx = insert_at_position($rootElements, $copy, $position);
                } else {
                    $pPath = find_index_path_by_id($rootElements, $newParentId);
                    if ($pPath === null) {
                        throw new RuntimeException("New parent '{$newParentId}' not found");
                    }
                    $pNode =& get_node_ref_by_index_path($rootElements, $pPath);
                    if (!isset($pNode['elements']) || !is_array($pNode['elements'])) {
                        $pNode['elements'] = [];
                    }
                    $newIdx = insert_at_position($pNode['elements'], $copy, $position);
                }

                $entry['source'] = path_to_string($srcPath);
                $entry['new_id'] = $copy['id'];
                $entry['to_index'] = $newIdx;
                break;
            }

            case 'insert_element': {
                $parentId = (string) ($op['parent_id'] ?? '__root__');
                $position = $op['position'] ?? 'end';
                $element = $op['element'] ?? null;
                if (!is_array($element)) {
                    throw new RuntimeException('insert_element requires element object');
                }
                ensure_element_shape($element);
                if (isset($idSet[$element['id']])) {
                    $element['id'] = generate_unique_id($idSet);
                } else {
                    $idSet[$element['id']] = true;
                }

                if ($parentId === '__root__' || $parentId === '') {
                    $newIdx = insert_at_position($rootElements, $element, $position);
                } else {
                    $pPath = find_index_path_by_id($rootElements, $parentId);
                    if ($pPath === null) {
                        throw new RuntimeException("Parent '{$parentId}' not found");
                    }
                    $pNode =& get_node_ref_by_index_path($rootElements, $pPath);
                    if (!isset($pNode['elements']) || !is_array($pNode['elements'])) {
                        $pNode['elements'] = [];
                    }
                    $newIdx = insert_at_position($pNode['elements'], $element, $position);
                }

                $entry['new_id'] = $element['id'];
                $entry['to_index'] = $newIdx;
                break;
            }

            default:
                throw new RuntimeException("Unsupported op '{$opName}'");
        }
    } catch (Throwable $e) {
        $entry['status'] = 'error';
        $entry['message'] = $e->getMessage();
        $changeLog['errors'][] = $entry;
        if (!$allowPartial) {
            $changeLog['aborted_on_error'] = true;
        }
    }

    $changeLog['operations'][] = $entry;

    if (!$allowPartial && $entry['status'] === 'error') {
        break;
    }
}

$ok = count($changeLog['errors']) === 0;
$rolledBack = (!$ok && !$allowPartial);
$finalData = $rolledBack ? $data : $workingData;

$changeLog['ok'] = $ok;
$changeLog['rolled_back'] = $rolledBack;
$changeLog['output'] = $output;

if (!$dryRun && $output !== null && $output !== '' && !$rolledBack) {
    $encoded = json_encode($finalData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($encoded === false || file_put_contents($output, $encoded) === false) {
        fwrite(STDERR, "Failed to write output file: {$output}\n");
        exit(1);
    }
    $changeLog['output_written'] = true;
} elseif ($rolledBack) {
    $changeLog['output_written'] = false;
    $changeLog['output_skipped_reason'] = 'rolled_back_on_error';
}

$logJson = json_encode($changeLog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($logFile !== null && $logFile !== '') {
    file_put_contents($logFile, $logJson);
}

fwrite(STDOUT, $logJson . "\n");
exit($ok ? 0 : 2);
