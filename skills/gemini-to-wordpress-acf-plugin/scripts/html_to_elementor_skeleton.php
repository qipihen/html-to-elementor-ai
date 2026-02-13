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
  php html_to_elementor_skeleton.php --input <page.html> --output <page.json>
      [--title <page title>] [--report <report.md|report.json>] [--report-format md|json]

Purpose:
  Convert static HTML into an Elementor-importable skeleton JSON.
  This is a structural converter (containers/widgets), not full style parity.
TXT;
    fwrite(STDOUT, $msg . "\n");
}

function next_id(int &$counter): string
{
    $counter++;
    return substr(str_pad(dechex($counter), 8, '0', STR_PAD_LEFT), -8);
}

function html_outer(DOMElement $el): string
{
    $doc = $el->ownerDocument;
    if ($doc === null) {
        return '';
    }
    $out = $doc->saveHTML($el);
    return is_string($out) ? $out : '';
}

function text_widget(string $text, int &$idCounter): array
{
    return [
        'id' => next_id($idCounter),
        'elType' => 'widget',
        'widgetType' => 'text-editor',
        'settings' => [
            'editor' => $text,
        ],
        'elements' => [],
    ];
}

function normalize_text(string $text): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text));
    return is_string($text) ? $text : '';
}

function node_to_elements(DOMNode $node, int &$idCounter, array &$stats): array
{
    if ($node->nodeType === XML_TEXT_NODE) {
        $text = normalize_text((string) $node->textContent);
        if ($text === '') {
            return [];
        }
        $stats['widgets']['text-editor'] = ($stats['widgets']['text-editor'] ?? 0) + 1;
        return [text_widget($text, $idCounter)];
    }

    if ($node->nodeType !== XML_ELEMENT_NODE) {
        return [];
    }

    /** @var DOMElement $el */
    $el = $node;
    $tag = strtolower($el->tagName);

    if (in_array($tag, ['script', 'style', 'noscript', 'meta', 'link'], true)) {
        $stats['skipped_tags'][$tag] = ($stats['skipped_tags'][$tag] ?? 0) + 1;
        return [];
    }

    $containerTags = [
        'div', 'section', 'main', 'article', 'header', 'footer', 'nav', 'aside',
        'form', 'fieldset', 'figure', 'figcaption',
    ];

    if (in_array($tag, $containerTags, true)) {
        $children = [];
        foreach ($el->childNodes as $child) {
            $mapped = node_to_elements($child, $idCounter, $stats);
            foreach ($mapped as $m) {
                $children[] = $m;
            }
        }

        if (count($children) === 0) {
            $text = normalize_text((string) $el->textContent);
            if ($text !== '') {
                $children[] = text_widget($text, $idCounter);
                $stats['widgets']['text-editor'] = ($stats['widgets']['text-editor'] ?? 0) + 1;
            }
        }

        $settings = ['html_tag' => $tag];
        $classes = trim((string) $el->getAttribute('class'));
        if ($classes !== '') {
            $settings['css_classes'] = $classes;
        }

        $stats['containers']++;
        return [[
            'id' => next_id($idCounter),
            'elType' => 'container',
            'settings' => $settings,
            'elements' => $children,
        ]];
    }

    if (preg_match('/^h([1-6])$/', $tag, $m) === 1) {
        $title = normalize_text((string) $el->textContent);
        $stats['widgets']['heading'] = ($stats['widgets']['heading'] ?? 0) + 1;
        return [[
            'id' => next_id($idCounter),
            'elType' => 'widget',
            'widgetType' => 'heading',
            'settings' => [
                'title' => $title,
                'header_size' => 'h' . $m[1],
            ],
            'elements' => [],
        ]];
    }

    if (in_array($tag, ['p', 'span', 'small', 'blockquote', 'pre', 'code', 'ul', 'ol', 'table'], true)) {
        $stats['widgets']['text-editor'] = ($stats['widgets']['text-editor'] ?? 0) + 1;
        return [[
            'id' => next_id($idCounter),
            'elType' => 'widget',
            'widgetType' => 'text-editor',
            'settings' => [
                'editor' => html_outer($el),
            ],
            'elements' => [],
        ]];
    }

    if ($tag === 'img') {
        $src = trim((string) $el->getAttribute('src'));
        $alt = trim((string) $el->getAttribute('alt'));
        $stats['widgets']['image'] = ($stats['widgets']['image'] ?? 0) + 1;
        return [[
            'id' => next_id($idCounter),
            'elType' => 'widget',
            'widgetType' => 'image',
            'settings' => [
                'image' => ['url' => $src, 'id' => 0],
                'image_size' => 'full',
                'image_alt' => $alt,
            ],
            'elements' => [],
        ]];
    }

    if ($tag === 'a' || $tag === 'button') {
        $class = strtolower(trim((string) $el->getAttribute('class')));
        $isButtonLike = $tag === 'button'
            || str_contains($class, 'btn')
            || str_contains($class, 'button')
            || strtolower((string) $el->getAttribute('role')) === 'button';

        if ($isButtonLike) {
            $text = normalize_text((string) $el->textContent);
            $url = $tag === 'a' ? trim((string) $el->getAttribute('href')) : '';
            $settings = ['text' => $text];
            if ($url !== '') {
                $settings['link'] = ['url' => $url, 'is_external' => false, 'nofollow' => false];
            }
            $stats['widgets']['button'] = ($stats['widgets']['button'] ?? 0) + 1;
            return [[
                'id' => next_id($idCounter),
                'elType' => 'widget',
                'widgetType' => 'button',
                'settings' => $settings,
                'elements' => [],
            ]];
        }

        $stats['widgets']['text-editor'] = ($stats['widgets']['text-editor'] ?? 0) + 1;
        return [[
            'id' => next_id($idCounter),
            'elType' => 'widget',
            'widgetType' => 'text-editor',
            'settings' => ['editor' => html_outer($el)],
            'elements' => [],
        ]];
    }

    if ($tag === 'iframe' || $tag === 'video') {
        $src = trim((string) $el->getAttribute('src'));
        if ($src === '' && $tag === 'video') {
            foreach ($el->getElementsByTagName('source') as $source) {
                $src = trim((string) $source->getAttribute('src'));
                if ($src !== '') {
                    break;
                }
            }
        }

        $isVideoProvider = str_contains(strtolower($src), 'youtube.com')
            || str_contains(strtolower($src), 'youtu.be')
            || str_contains(strtolower($src), 'vimeo.com');

        if ($isVideoProvider) {
            $stats['widgets']['video'] = ($stats['widgets']['video'] ?? 0) + 1;
            return [[
                'id' => next_id($idCounter),
                'elType' => 'widget',
                'widgetType' => 'video',
                'settings' => [
                    'youtube_url' => $src,
                ],
                'elements' => [],
            ]];
        }

        $stats['widgets']['html'] = ($stats['widgets']['html'] ?? 0) + 1;
        return [[
            'id' => next_id($idCounter),
            'elType' => 'widget',
            'widgetType' => 'html',
            'settings' => [
                'html' => html_outer($el),
            ],
            'elements' => [],
        ]];
    }

    $children = [];
    foreach ($el->childNodes as $child) {
        $mapped = node_to_elements($child, $idCounter, $stats);
        foreach ($mapped as $m) {
            $children[] = $m;
        }
    }

    if (count($children) > 0) {
        $stats['containers']++;
        return [[
            'id' => next_id($idCounter),
            'elType' => 'container',
            'settings' => ['html_tag' => $tag],
            'elements' => $children,
        ]];
    }

    $stats['widgets']['html'] = ($stats['widgets']['html'] ?? 0) + 1;
    return [[
        'id' => next_id($idCounter),
        'elType' => 'widget',
        'widgetType' => 'html',
        'settings' => [
            'html' => html_outer($el),
        ],
        'elements' => [],
    ]];
}

function write_report(array $stats, string $input, string $output, ?string $reportPath, string $format): void
{
    if ($reportPath === null || trim($reportPath) === '') {
        return;
    }

    if ($format === 'json') {
        file_put_contents($reportPath, json_encode([
            'input' => $input,
            'output' => $output,
            'stats' => $stats,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return;
    }

    $lines = [];
    $lines[] = '# HTML To Elementor Skeleton Report';
    $lines[] = '';
    $lines[] = '- Input: `' . $input . '`';
    $lines[] = '- Output: `' . $output . '`';
    $lines[] = '- Containers: `' . $stats['containers'] . '`';
    $lines[] = '- Top-level elements: `' . $stats['top_level'] . '`';
    $lines[] = '';
    $lines[] = '## Widgets';
    foreach ($stats['widgets'] as $k => $v) {
        $lines[] = '- `' . $k . '`: `' . $v . '`';
    }
    if (count($stats['widgets']) === 0) {
        $lines[] = '- none';
    }
    $lines[] = '';
    if (count($stats['skipped_tags']) > 0) {
        $lines[] = '## Skipped Tags';
        foreach ($stats['skipped_tags'] as $k => $v) {
            $lines[] = '- `' . $k . '`: `' . $v . '`';
        }
        $lines[] = '';
    }

    file_put_contents($reportPath, implode("\n", $lines));
}

$options = getopt('', [
    'input:',
    'output:',
    'title:',
    'report:',
    'report-format:',
]);

$input = $options['input'] ?? null;
$output = $options['output'] ?? null;
$title = $options['title'] ?? null;
$report = $options['report'] ?? null;
$reportFormat = $options['report-format'] ?? 'md';

if ($input === null || $output === null) {
    usage();
    exit(1);
}
if (!is_file($input)) {
    fwrite(STDERR, "Input file not found: {$input}\n");
    exit(1);
}
if (!in_array($reportFormat, ['md', 'json'], true)) {
    fwrite(STDERR, "--report-format must be md or json.\n");
    exit(1);
}

$raw = file_get_contents($input);
if ($raw === false) {
    fwrite(STDERR, "Failed to read input file.\n");
    exit(1);
}

$doc = new DOMDocument('1.0', 'UTF-8');
libxml_use_internal_errors(true);
$loaded = $doc->loadHTML($raw, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
libxml_clear_errors();
if ($loaded === false) {
    fwrite(STDERR, "Failed to parse HTML.\n");
    exit(1);
}

$body = $doc->getElementsByTagName('body')->item(0);
$rootNode = $body instanceof DOMElement ? $body : $doc->documentElement;
if (!$rootNode instanceof DOMElement) {
    fwrite(STDERR, "No parseable root element found.\n");
    exit(1);
}

$idCounter = 0;
$stats = [
    'containers' => 0,
    'widgets' => [],
    'skipped_tags' => [],
    'top_level' => 0,
];

$content = [];
foreach ($rootNode->childNodes as $child) {
    $mapped = node_to_elements($child, $idCounter, $stats);
    foreach ($mapped as $m) {
        $content[] = $m;
    }
}
$stats['top_level'] = count($content);

$pageTitle = is_string($title) && trim($title) !== '' ? trim($title) : 'Converted HTML Skeleton';
$json = [
    'title' => $pageTitle,
    'type' => 'page',
    'version' => '0.4',
    'page_settings' => [],
    'content' => $content,
];

$encoded = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($encoded === false) {
    fwrite(STDERR, "Failed to encode JSON output.\n");
    exit(1);
}

$outputDir = dirname($output);
if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Failed to create output directory: {$outputDir}\n");
    exit(1);
}

if (file_put_contents($output, $encoded) === false) {
    fwrite(STDERR, "Failed to write output file: {$output}\n");
    exit(1);
}

write_report($stats, $input, $output, $report, $reportFormat);
fwrite(STDOUT, "Generated Elementor skeleton: {$output}\n");
exit(0);

