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
  php decide_delivery_route.php [--source <file>] [--data <0-3>] [--motion <0-3>] [--runtime <0-3>] [--json]

Rules:
  total 0-2  => elementor-json
  total 3-5  => hybrid
  total >=6  => plugin
TXT;
    fwrite(STDOUT, $msg . "\n");
}

function clamp_score(int $v): int
{
    if ($v < 0) return 0;
    if ($v > 3) return 3;
    return $v;
}

function score_from_weight(int $weight): int
{
    if ($weight <= 0) return 0;
    if ($weight <= 2) return 1;
    if ($weight <= 5) return 2;
    return 3;
}

function score_from_signal_map(string $content, array $signalMap, array &$matched): int
{
    $weight = 0;
    foreach ($signalMap as $p => $w) {
        if (preg_match('/' . $p . '/i', $content) === 1) {
            $weight += (int) $w;
            $matched[] = [
                'pattern' => $p,
                'weight' => (int) $w,
            ];
        }
    }
    return score_from_weight($weight);
}

$options = getopt('', ['source:', 'data:', 'motion:', 'runtime:', 'json']);
if ($options === false) {
    usage();
    exit(1);
}

$source = $options['source'] ?? null;
$manualData = array_key_exists('data', $options) ? clamp_score((int)$options['data']) : null;
$manualMotion = array_key_exists('motion', $options) ? clamp_score((int)$options['motion']) : null;
$manualRuntime = array_key_exists('runtime', $options) ? clamp_score((int)$options['runtime']) : null;
$jsonOut = array_key_exists('json', $options);

$content = '';
if ($source !== null) {
    if (!is_file($source)) {
        fwrite(STDERR, "Source file not found: {$source}\n");
        exit(1);
    }
    $content = (string) file_get_contents($source);
}

$dataMatched = [];
$motionMatched = [];
$runtimeMatched = [];

$dataSignals = [
    '\btaxonomy\b' => 3,
    '\bwoocommerce\b' => 3,
    '\bacf\b' => 2,
    '\brepeater\b' => 2,
    '\bpost_type\b' => 2,
    '\bquery\b' => 2,
    '\bpagination\b' => 2,
    '\bfilters?\b' => 1,
    '\bsearch\b' => 1,
    '\bsort\b' => 1,
    '\bapi\b' => 1,
    '\bajax\b' => 1,
    '\bdynamic\b' => 1,
    '\bfacet\b' => 1,
];
$motionSignals = [
    '\bgsap\b' => 3,
    '\bscrolltrigger\b' => 3,
    '\bframer-motion\b' => 3,
    '\banime\.js\b' => 2,
    '\bparallax\b' => 2,
    '\btimeline\b' => 2,
    '\blottie\b' => 2,
    '\blocomotive\b' => 2,
    '\blenis\b' => 2,
    '\bintersectionobserver\b' => 1,
    '\bkeyframes\b' => 1,
];
$runtimeSignals = [
    '\busestate\s*\(' => 2,
    '\buseeffect\s*\(' => 2,
    '\baddEventListener\s*\(' => 2,
    '\bwebsocket\b' => 3,
    '\bsocket\.io\b' => 3,
    '\bredux\b' => 2,
    '\bvuex\b' => 2,
    '\bpinia\b' => 2,
    '\bdebounce\s*\(' => 2,
    '\bthrottle\s*\(' => 2,
    '\bintersectionobserver\b' => 1,
    '\bmutationobserver\b' => 1,
    '\blocalstorage\b' => 1,
    '\bsessionstorage\b' => 1,
];

$autoData = score_from_signal_map($content, $dataSignals, $dataMatched);
$autoMotion = score_from_signal_map($content, $motionSignals, $motionMatched);
$autoRuntime = score_from_signal_map($content, $runtimeSignals, $runtimeMatched);

$dataScore = $manualData ?? $autoData;
$motionScore = $manualMotion ?? $autoMotion;
$runtimeScore = $manualRuntime ?? $autoRuntime;

$total = $dataScore + $motionScore + $runtimeScore;
if ($total <= 2) {
    $route = 'elementor-json';
} elseif ($total <= 5) {
    $route = 'hybrid';
} else {
    $route = 'plugin';
}

$confidence = 'medium';
if ($manualData !== null || $manualMotion !== null || $manualRuntime !== null) {
    $confidence = 'high';
} elseif ($source === null || trim($content) === '') {
    $confidence = 'low';
}

$result = [
    'route' => $route,
    'scores' => [
        'data' => $dataScore,
        'motion' => $motionScore,
        'runtime' => $runtimeScore,
        'total' => $total,
    ],
    'source' => $source,
    'confidence' => $confidence,
    'matched_signals' => [
        'data' => $dataMatched,
        'motion' => $motionMatched,
        'runtime' => $runtimeMatched,
    ],
    'rules' => [
        '0-2' => 'elementor-json',
        '3-5' => 'hybrid',
        '>=6' => 'plugin',
        'downgrade_on_parity_fail' => 'elementor-json -> hybrid/plugin',
    ],
];

if ($jsonOut) {
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    exit(0);
}

fwrite(STDOUT, "Recommended route: {$result['route']}\n");
fwrite(STDOUT, "Scores: data={$dataScore}, motion={$motionScore}, runtime={$runtimeScore}, total={$total}\n");
fwrite(STDOUT, "Confidence: {$confidence}\n");
if ($source !== null) {
    fwrite(STDOUT, "Source: {$source}\n");
}

exit(0);
