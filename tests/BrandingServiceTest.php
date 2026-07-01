<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Stub Dolibarr functions used by the service so the test runs standalone.
$GLOBALS['__test_consts'] = [];
if (!function_exists('getDolGlobalString')) {
    function getDolGlobalString(string $name, string $default = ''): string {
        return $GLOBALS['__test_consts'][$name] ?? $default;
    }
}
if (!defined('DOL_URL_ROOT')) {
    define('DOL_URL_ROOT', '');
}
if (!function_exists('dol_buildpath')) {
    function dol_buildpath(string $path, int $type = 0): string {
        return '/custom' . $path;
    }
}

use Dalfred\Service\BrandingService;

$failures = 0;

function assertEq($expected, $actual, string $message): void {
    global $failures;
    if ($expected !== $actual) {
        echo "  FAIL  {$message}\n    expected: " . var_export($expected, true) . "\n    actual:   " . var_export($actual, true) . "\n";
        $failures++;
    } else {
        echo "  OK    {$message}\n";
    }
}

function assertContains(string $needle, string $haystack, string $message): void {
    global $failures;
    if (!str_contains($haystack, $needle)) {
        echo "  FAIL  {$message}\n    expected to find: {$needle}\n    in: " . substr($haystack, 0, 200) . "\n";
        $failures++;
    } else {
        echo "  OK    {$message}\n";
    }
}

echo "=== Defaults (no constants set) ===\n";
$GLOBALS['__test_consts'] = [];
$svc = new BrandingService();
assertEq('Dalfred', $svc->getName(), 'default name');
assertEq('#4CAF50', $svc->getPrimaryColor(), 'default primary');
assertEq('#45a049', $svc->getSecondaryColor(), 'default secondary');
assertEq(null, $svc->getLogoUrl(), 'logo url is null when no logo');

echo "\n=== Custom name ===\n";
$GLOBALS['__test_consts'] = ['DALFRED_BRAND_NAME' => 'MonAgent'];
$svc = new BrandingService();
assertEq('MonAgent', $svc->getName(), 'custom name returned');

echo "\n=== Custom colors ===\n";
$GLOBALS['__test_consts'] = [
    'DALFRED_BRAND_COLOR_PRIMARY' => '#ff0000',
    'DALFRED_BRAND_COLOR_SECONDARY' => '#00ff00',
];
$svc = new BrandingService();
assertEq('#ff0000', $svc->getPrimaryColor(), 'custom primary');
assertEq('#00ff00', $svc->getSecondaryColor(), 'custom secondary');

echo "\n=== Invalid color rejected, defaults to original ===\n";
$GLOBALS['__test_consts'] = [
    'DALFRED_BRAND_COLOR_PRIMARY' => 'javascript:alert(1)',
];
$svc = new BrandingService();
assertEq('#4CAF50', $svc->getPrimaryColor(), 'invalid primary falls back to default');

echo "\n=== getCssVariables ===\n";
$GLOBALS['__test_consts'] = [
    'DALFRED_BRAND_COLOR_PRIMARY' => '#ff0000',
    'DALFRED_BRAND_COLOR_SECONDARY' => '#00ff00',
];
$svc = new BrandingService();
$css = $svc->getCssVariables();
assertContains('--dalfred-primary: #ff0000', $css, 'primary in css output');
assertContains('--dalfred-secondary: #00ff00', $css, 'secondary in css output');
assertContains(':root', $css, ':root selector present');

echo "\n=== getAgentIconHtml falls back to bot icon when no logo ===\n";
$GLOBALS['__test_consts'] = [];
$svc = new BrandingService();
$html = $svc->getAgentIconHtml();
assertContains('<svg', $html, 'fallback returns SVG');
assertContains('lucide', $html, 'fallback uses Lucide bot icon');

echo "\n=== getAgentIconHtml uses uploaded logo when present ===\n";
$GLOBALS['__test_consts'] = ['DALFRED_BRAND_LOGO_PATH' => 'logo.png'];
$svc = new BrandingService();
$html = $svc->getAgentIconHtml();
assertContains('<img', $html, 'uploaded logo rendered as <img>');
assertContains('logo.png', $html, 'logo filename appears in src');

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s)\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
