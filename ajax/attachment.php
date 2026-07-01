<?php

declare(strict_types=1);

/**
 * Serves a single attached file from documents/dalfred/attachments/.
 * URL: /custom/dalfred/ajax/attachment.php?path={threadId}/{filename}&token={csrf}
 *
 * Security:
 *   - login required (Dolibarr session)
 *   - CSRF token must match the session
 *   - path must match a strict regex (no traversal possible)
 *   - resolved realpath must live inside the attachments root
 *   - the {threadId} segment must reference a thread owned by the user
 */

if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');
if (!defined('NOREQUIREMENU'))  define('NOREQUIREMENU', '1');
if (!defined('NOREQUIREHTML'))  define('NOREQUIREHTML', '1');
if (!defined('NOREQUIREAJAX'))  define('NOREQUIREAJAX', '1');

$res = 0;
if (!$res && file_exists("../../../main.inc.php"))    $res = @include "../../../main.inc.php";
if (!$res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (!$res) { http_response_code(500); exit('Bootstrap failed'); }

require_once __DIR__ . '/../vendor/autoload.php';

use Dalfred\Service\FileAttachmentService;
use Dalfred\Service\ThreadService;

if (empty($user->id)) {
    http_response_code(401);
    exit('Auth required');
}

$token = (string) GETPOST('token', 'alphanohtml');
if ($token === '' || $token !== ($_SESSION['token'] ?? '')) {
    http_response_code(403);
    exit('Invalid token');
}

$path = (string) GETPOST('path', 'alphanohtml');
// Must be {threadId}/{filename.ext} with safe charset.
if (!preg_match('#^[A-Za-z0-9_-]+/[A-Za-z0-9._-]+\.(txt|md|log|csv|jpg|jpeg|png|gif|webp)$#', $path)) {
    http_response_code(404);
    exit('Not found');
}

$root = FileAttachmentService::storageRoot();
$candidate = $root . '/' . $path;
$realRoot = realpath($root);
$realFile = realpath($candidate);
if ($realRoot === false || $realFile === false
    || !str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR)
    || !is_file($realFile)
) {
    http_response_code(404);
    exit('Not found');
}

// Ownership: the {threadId} part must belong to the current user.
[$threadId, ] = explode('/', $path, 2);
$threadService = new ThreadService($db, (int) $user->id, (int) $conf->entity);
if (!$threadService->threadExists($threadId)) {
    http_response_code(403);
    exit('Forbidden');
}

$ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'txt', 'log' => 'text/plain; charset=utf-8',
    'md'         => 'text/markdown; charset=utf-8',
    'csv'        => 'text/csv; charset=utf-8',
    'png'        => 'image/png',
    'jpg', 'jpeg'=> 'image/jpeg',
    'gif'        => 'image/gif',
    'webp'       => 'image/webp',
    default      => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($realFile));
header('Cache-Control: private, max-age=3600');
readfile($realFile);
exit;
