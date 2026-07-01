<?php

declare(strict_types=1);

/**
 * Public endpoint serving the uploaded brand logo.
 * URL: /custom/dalfred/ajax/branding.php?file=logo.png
 *
 * Strict whitelist on the file name to prevent path traversal.
 */

if (!defined('NOTOKENRENEWAL'))    define('NOTOKENRENEWAL', '1');
if (!defined('NOREQUIREMENU'))     define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML'))     define('NOREQUIREHTML', '1');
if (!defined('NOREQUIREAJAX'))     define('NOREQUIREAJAX', '1');
if (!defined('NOLOGIN'))           define('NOLOGIN', '1');

// Bootstrap Dolibarr (DOL_DATA_ROOT etc).
$res = 0;
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
    $res = @include "../../../../main.inc.php";
}
if (!$res) {
    http_response_code(500);
    exit('Dolibarr bootstrap failed');
}

$file = isset($_GET['file']) ? (string) $_GET['file'] : '';

// Whitelist: only logo.png / logo.svg / logo.jpg are valid.
if (!preg_match('/^logo\.(png|svg|jpg)$/', $file)) {
    http_response_code(404);
    exit('Not found');
}

$brandingDir = DOL_DATA_ROOT . '/dalfred/branding';
$path = $brandingDir . '/' . $file;
$realBranding = realpath($brandingDir);
$realFile = realpath($path);

// realpath() returns false if missing — second guard against traversal.
if ($realBranding === false || $realFile === false || !str_starts_with($realFile, $realBranding . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('Not found');
}

$mime = match (pathinfo($realFile, PATHINFO_EXTENSION)) {
    'png' => 'image/png',
    'svg' => 'image/svg+xml',
    'jpg' => 'image/jpeg',
    default => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($realFile));
header('Cache-Control: public, max-age=3600');
readfile($realFile);
exit;
