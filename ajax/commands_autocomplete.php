<?php

declare(strict_types=1);

/**
 * Dalfred AJAX — list commands matching a prefix, for the chat textarea
 * autocomplete dropdown.
 *
 * Read-only, no side effects. Auth = logged-in user with dalfred->use right.
 *
 * @package Dalfred
 * @license GPL-3.0+
 */

if (function_exists('error_reporting')) { error_reporting(E_ERROR | E_PARSE); }
if (function_exists('ini_set')) { ini_set('display_errors', '0'); }

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

if (!defined('NOCSRFCHECK')) define('NOCSRFCHECK', '1');
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');
if (!defined('NOSCANPHPSELFFORINJECTION')) define('NOSCANPHPSELFFORINJECTION', 1);
if (!defined('NOSCANGETFORINJECTION')) define('NOSCANGETFORINJECTION', 1);
if (!defined('NOSCANPOSTFORINJECTION')) define('NOSCANPOSTFORINJECTION', 1);

$res = 0;
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res && file_exists("../../../../main.inc.php")) { $res = @include "../../../../main.inc.php"; }
if (!$res) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'env']);
    exit;
}

require_once dol_buildpath('/dalfred/vendor/autoload.php');

if (empty($user->id)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthorized']);
    exit;
}
if (!isModEnabled('dalfred') || !$user->hasRight('dalfred', 'use')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'forbidden']);
    exit;
}

// Prefix is user-controlled — strip non-allowed chars before passing to the
// resolver so a malformed input never reaches the LIKE clause untrimmed.
// CommandResolver also caps the length, but defense in depth.
$rawPrefix = (string) GETPOST('prefix', 'alpha');
$prefix = preg_replace('/[^a-z0-9-]/', '', strtolower($rawPrefix));
if (strlen($prefix) > 64) {
    $prefix = substr($prefix, 0, 64);
}

$resolver = new \Dalfred\Service\CommandResolver($db);
$commands = $resolver->listAvailable(
    (int) $user->id,
    (int) $conf->entity,
    $prefix === '' ? null : $prefix,
    20
);

echo json_encode([
    'success' => true,
    'commands' => $commands,
], JSON_UNESCAPED_UNICODE);
