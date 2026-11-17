<?php

declare(strict_types=1);

/*
 * Files a previous Dalfred package shipped and the current one must not leave
 * behind when a customer unzips the new version over the old one.
 * Runs on a scratch module tree, never on the real module directory.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Service\ObsoleteFilesCleaner;

$failures = 0;

function check(bool $ok, string $msg, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  OK    ' : '  FAIL  ') . $msg . ($ok || $detail === '' ? '' : "\n        " . $detail) . "\n";
    if (!$ok) {
        $failures++;
    }
}

function rrmdir(string $dir): void
{
    if (!file_exists($dir) && !is_link($dir)) {
        return;
    }
    if (is_link($dir) || is_file($dir)) {
        unlink($dir);
        return;
    }
    foreach (scandir($dir) as $e) {
        if ($e !== '.' && $e !== '..') {
            rrmdir($dir . '/' . $e);
        }
    }
    rmdir($dir);
}

/** A module tree as an installed 2.34.0 left it. */
function makeModule(string $base): string
{
    $mod = $base . '/dalfred';
    @mkdir($mod . '/core/modules', 0777, true);
    file_put_contents($mod . '/core/modules/modDalfred.class.php', '<?php');
    @mkdir($mod . '/dolibarr-mcp-server/public', 0777, true);
    @mkdir($mod . '/dolibarr-mcp-server/bin', 0777, true);
    @mkdir($mod . '/dolibarr-mcp-server/src', 0777, true);
    file_put_contents($mod . '/dolibarr-mcp-server/public/index.php', '<?php // standalone server');
    file_put_contents($mod . '/dolibarr-mcp-server/bin/server.php', '<?php');
    file_put_contents($mod . '/dolibarr-mcp-server/src/Bootstrap.php', '<?php');

    return $mod;
}

$base = sys_get_temp_dir() . '/dalfred-cleaner-' . getmypid();
rrmdir($base);
mkdir($base);

echo "=== Removes public/ and bin/, nothing else ===\n";
$mod = makeModule($base . '/a');
$outside = $base . '/a/outside.txt';
file_put_contents($outside, 'keep');
$r = (new ObsoleteFilesCleaner($mod))->clean();
check(!file_exists($mod . '/dolibarr-mcp-server/public'), 'public/ removed');
check(!file_exists($mod . '/dolibarr-mcp-server/bin'), 'bin/ removed');
check(is_file($mod . '/dolibarr-mcp-server/src/Bootstrap.php'), 'src/ kept');
check(is_file($outside), 'nothing outside the module touched');
check($r['failed'] === [] && count($r['removed']) === 2, 'result lists both removals', json_encode($r));
check((new ObsoleteFilesCleaner($mod))->remaining() === [], 'nothing remains');

echo "=== Second run is a no-op ===\n";
$r = (new ObsoleteFilesCleaner($mod))->clean();
check($r['removed'] === [] && $r['failed'] === [] && !$r['skipped'], 'idempotent', json_encode($r));

echo "=== A symlink is removed, its target is not followed ===\n";
$mod = makeModule($base . '/b');
$target = $base . '/b/elsewhere';
mkdir($target);
file_put_contents($target . '/precious.txt', 'keep');
rrmdir($mod . '/dolibarr-mcp-server/public');
symlink($target, $mod . '/dolibarr-mcp-server/public');
file_put_contents($mod . '/dolibarr-mcp-server/bin/link-target.txt', 'x');
symlink($target . '/precious.txt', $mod . '/dolibarr-mcp-server/bin/evil-link');
$r = (new ObsoleteFilesCleaner($mod))->clean();
check(!is_link($mod . '/dolibarr-mcp-server/public') && !file_exists($mod . '/dolibarr-mcp-server/public'), 'symlinked public/ removed');
check(!file_exists($mod . '/dolibarr-mcp-server/bin'), 'bin/ with an inner symlink removed');
check(is_file($target . '/precious.txt'), 'symlink targets untouched');

echo "=== Development checkout (engine is a git repository): skipped ===\n";
$mod = makeModule($base . '/c');
mkdir($mod . '/dolibarr-mcp-server/.git');
$r = (new ObsoleteFilesCleaner($mod))->clean();
check($r['skipped'] === true, 'reported as skipped');
check(is_file($mod . '/dolibarr-mcp-server/public/index.php'), 'public/ left alone in a dev checkout');
check((new ObsoleteFilesCleaner($mod))->remaining() === [], 'dev checkout reports nothing to clean');

echo "=== Not a Dalfred module root: refuses ===\n";
$notModule = $base . '/d';
mkdir($notModule . '/dolibarr-mcp-server/public', 0777, true);
file_put_contents($notModule . '/dolibarr-mcp-server/public/index.php', 'x');
$r = (new ObsoleteFilesCleaner($notModule))->clean();
check(is_file($notModule . '/dolibarr-mcp-server/public/index.php'), 'nothing removed');
check($r['failed'] !== [], 'refusal reported', json_encode($r));

echo "=== Unwritable directory: reported, no exception ===\n";
if (function_exists('posix_getuid') && posix_getuid() === 0) {
    echo "  --    skipped as root (permissions are not enforced)\n";
} else {
    $mod = makeModule($base . '/e');
    chmod($mod . '/dolibarr-mcp-server', 0555);
    try {
        $r = (new ObsoleteFilesCleaner($mod))->clean();
        check($r['failed'] !== [], 'failure reported', json_encode($r));
        check((new ObsoleteFilesCleaner($mod))->remaining() !== [], 'remaining() lists what is left');
    } catch (\Throwable $e) {
        check(false, 'no exception', get_class($e) . ': ' . $e->getMessage());
    }
    chmod($mod . '/dolibarr-mcp-server', 0755);
}

rrmdir($base);
echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n{$failures} TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
