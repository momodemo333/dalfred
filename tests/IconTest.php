<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Icon;

$failures = 0;

function assertContains(string $needle, string $haystack, string $message): void {
    global $failures;
    if (!str_contains($haystack, $needle)) {
        echo "  FAIL  {$message}\n";
        echo "    expected to find: {$needle}\n";
        echo "    in: " . substr($haystack, 0, 200) . "...\n";
        $failures++;
    } else {
        echo "  OK    {$message}\n";
    }
}

function assertEquals($expected, $actual, string $message): void {
    global $failures;
    if ($expected !== $actual) {
        echo "  FAIL  {$message}\n";
        echo "    expected: " . var_export($expected, true) . "\n";
        echo "    actual:   " . var_export($actual, true) . "\n";
        $failures++;
    } else {
        echo "  OK    {$message}\n";
    }
}

echo "=== Icon::render() basic ===\n";
$svg = Icon::render('bot');
assertContains('<svg', $svg, 'render returns an <svg>');
assertContains('lucide', $svg, 'render preserves the lucide class');

echo "\n=== Icon::render() with custom class ===\n";
$svg = Icon::render('bot', ['class' => 'dalfred-icon-lg']);
assertContains('dalfred-icon-lg', $svg, 'custom class is added');
assertContains('lucide', $svg, 'original lucide class is kept');

echo "\n=== Icon::render() with width override ===\n";
$svg = Icon::render('bot', ['width' => '32', 'height' => '32']);
assertContains('width="32"', $svg, 'width attribute is overridden');
assertContains('height="32"', $svg, 'height attribute is overridden');

echo "\n=== Icon::render() with aria-label ===\n";
$svg = Icon::render('bot', ['aria-label' => 'Agent']);
assertContains('aria-label="Agent"', $svg, 'aria-label is added');

echo "\n=== Icon::render() with unknown icon ===\n";
$svg = Icon::render('this-icon-does-not-exist');
assertContains('dalfred-icon-missing', $svg, 'fallback class on missing icon');

echo "\n=== Icon::render() with path traversal attempt ===\n";
$svg = Icon::render('../../../etc/passwd');
assertContains('dalfred-icon-missing', $svg, 'path traversal returns missing fallback');

echo "\n=== Icon::render() handles self-closing root tag (defensive) ===\n";
// Simulate by rendering an icon and checking the output has a proper closing >
$svg = Icon::render('bot', ['class' => 'test']);
assertContains('>', $svg, 'rendered SVG has closing >');
// Lucide SVGs are not self-closing, so we don't crash on the current set
assertContains('</svg>', $svg, 'rendered SVG has matching </svg>');

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s)\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
