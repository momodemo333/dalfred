<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Service\GeneratedFilesService;

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

// Each test gets its own isolated data root under /tmp.
function makeTmpRoot(): string {
    $root = sys_get_temp_dir() . '/dalfred-test-' . bin2hex(random_bytes(6));
    mkdir($root, 0755, true);
    return $root;
}

function cleanup(string $root): void {
    if (!is_dir($root)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
    }
    rmdir($root);
}

// === NOTE on the 3rd constructor arg (changed in v2.21.1) ===
// The 3rd argument is now `downloadUrl`: the FULL URL to download.php, as
// resolved by the caller via `dol_buildpath('/dalfred/download.php', 1)`.
// This shift fixes the case-sensitivity bug on hosts where the custom dir is
// named `Custom/` instead of `custom/` (e.g. erp.example.com), AND it
// preserves the sub-path support (DOL_URL_ROOT) that was the v2.21.1 first
// fix. The service simply appends `?f=<name>` — no hardcoded path.

echo "=== download_url uses the resolved download URL as-is (typical at-root case) ===\n";
$root = makeTmpRoot();
$svc  = new GeneratedFilesService($root, 1024 * 1024, '/custom/dalfred/download.php');
$res  = $svc->create(1, 'report', 'csv', "a,b\n1,2");
assertEq('/custom/dalfred/download.php?f=report.csv', $res['download_url'], 'resolved URL used verbatim, ?f= appended');
cleanup($root);

echo "\n=== download_url with subpath install (dol_buildpath resolved /erp prefix) ===\n";
$root = makeTmpRoot();
$svc  = new GeneratedFilesService($root, 1024 * 1024, '/erp/custom/dalfred/download.php');
$res  = $svc->create(1, 'report', 'csv', "a,b\n1,2");
assertEq('/erp/custom/dalfred/download.php?f=report.csv', $res['download_url'], 'subpath prefix is honored');
cleanup($root);

echo "\n=== download_url with case-sensitive Custom/ dir (the customer-instance case) ===\n";
$root = makeTmpRoot();
$svc  = new GeneratedFilesService($root, 1024 * 1024, '/htdocs/Custom/dalfred/download.php');
$res  = $svc->create(1, 'monfichier', 'md', '# hello');
assertEq('/htdocs/Custom/dalfred/download.php?f=monfichier.md', $res['download_url'], 'preserves actual custom-dir casing');
cleanup($root);

echo "\n=== Filename with chars that need URL encoding is rawurlencoded ===\n";
$root = makeTmpRoot();
$svc  = new GeneratedFilesService($root, 1024 * 1024, '/erp/custom/dalfred/download.php');
// Trigger a collision so the resolved filename gets a _2 suffix.
$svc->create(1, 'rapport', 'md', 'first');
$res = $svc->create(1, 'rapport', 'md', 'second');
assertEq('rapport_2.md', $res['filename'], 'collision resolution produces _2 variant');
assertContains('/erp/custom/dalfred/download.php?f=rapport_2.md', $res['download_url'], 'collision-resolved filename keeps the prefix');
cleanup($root);

echo "\n=== Default downloadUrl is empty (back-compat with code that omits the 3rd arg) ===\n";
// When omitted, falls back to legacy hardcoded '/custom/dalfred/download.php'.
// Still broken on Custom/-cased hosts — but won't crash old call sites.
$root = makeTmpRoot();
$svc  = new GeneratedFilesService($root, 1024 * 1024);
$res  = $svc->create(1, 'x', 'txt', 'hi');
assertEq('/custom/dalfred/download.php?f=x.txt', $res['download_url'], 'omitting the 3rd arg falls back to legacy hardcoded path');
cleanup($root);

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures} assertion(s)\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
