<?php
/**
 * Dalfred Diagnostic AJAX — minimal sleep endpoint for timeout testing.
 *
 * Mimics what a long Dalfred answer does to the worker, without paying
 * AI tokens or depending on the provider's mood. Reproduces the same
 * stack path as ajax/chat.php (session lock release, fastcgi, …).
 *
 * Admin-only. Hard-clamped to [1, 180] seconds.
 *
 * @package Dalfred
 */

// Disable warnings display for AJAX responses to prevent JSON corruption
if (function_exists('error_reporting')) {
    error_reporting(E_ERROR | E_PARSE);
}
if (function_exists('ini_set')) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
}

// Security headers
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// IMPORTANT: Define constants BEFORE including any Dolibarr files
if (!defined('NOCSRFCHECK')) define('NOCSRFCHECK', '1');
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');
if (!defined('NOSCANPHPSELFFORINJECTION')) define('NOSCANPHPSELFFORINJECTION', 1);
if (!defined('NOSCANGETFORINJECTION')) define('NOSCANGETFORINJECTION', 1);

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
    $res = @include "../../../../main.inc.php";
}
if (!$res) {
    http_response_code(500);
    die(json_encode(['success' => false, 'error' => 'Failed to load Dolibarr environment']));
}

// Admin only
if (!$user || !$user->id) {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'Authentification requise']));
}
if (!$user->admin) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Accès admin requis']));
}

// Release the session lock so the timeout test doesn't itself hold up
// other tabs / requests on the same Dolibarr session.
session_write_close();

$action = isset($_GET['action']) ? (string) $_GET['action'] : '';

if ($action === 'sleep') {
    $seconds = isset($_GET['seconds']) ? (int) $_GET['seconds'] : 5;
    // 600s ceiling matches DiagnosticService::RECOMMENDED_TIMEOUT_S — operators
    // need to be able to test the upper bound of what they just configured.
    $seconds = max(1, min(600, $seconds));

    $start = microtime(true);
    dol_syslog('[Dalfred] diagnostic sleep start: ' . $seconds . 's', LOG_WARNING);

    sleep($seconds);

    $elapsed = microtime(true) - $start;
    dol_syslog('[Dalfred] diagnostic sleep end: ' . round($elapsed, 3) . 's', LOG_WARNING);

    echo json_encode([
        'success'           => true,
        'requested_seconds' => $seconds,
        'elapsed_seconds'   => round($elapsed, 3),
        'message'           => 'Slept ' . $seconds . ' second(s) — server-side timing OK',
    ]);
    exit;
}

// Default: return the snapshot as JSON (handy for "Copy report" too)
require_once __DIR__ . '/../vendor/autoload.php';
$svc = new \Dalfred\Service\DiagnosticService();
echo json_encode([
    'success'  => true,
    'snapshot' => $svc->snapshot(),
]);
