<?php
/**
 * Dalfred AJAX — token usage for the current thread.
 *
 * Used by the chat badge to display the percentage of context window
 * consumed by the conversation so far.
 *
 * Endpoint: /custom/dalfred/ajax/usage.php?thread_id=<id>
 * Response shape (JSON):
 *   {
 *     "success":            bool,
 *     "input_tokens":       int,    // cumulative across the thread (cost view)
 *     "output_tokens":      int,    // cumulative across the thread (cost view)
 *     "total_tokens":       int,    // cumulative input + output (cost view)
 *     "last_input_tokens":  int,    // input of the most recent inference
 *     "last_output_tokens": int,    // output of the most recent inference
 *     "context_used":       int,    // last_input + last_output (saturation view)
 *     "context_window":     int,
 *     "percent":            float,  // 100 * context_used / context_window
 *     "model":              string,
 *     "inference_count":    int
 *   }
 *
 * Why two views: summing input_tokens across inferences double-counts the
 * conversation history (each call re-sends every prior turn). The saturation
 * gauge has to use the last call only; the cumulative view stays for the
 * tooltip and cost reporting.
 *
 * @package    Dalfred
 * @license    GPL-3.0+
 */

// Disable warnings display for AJAX responses to prevent JSON corruption.
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
if (!defined('NOSCANPOSTFORINJECTION')) define('NOSCANPOSTFORINJECTION', 1);

// Load Dolibarr environment (same pattern as ajax/chat.php and ajax/thread.php)
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

// Load autoloader for Dalfred classes
require_once dol_buildpath('/dalfred/vendor/autoload.php');

use Dalfred\Repository\TokenUsageRepository;
use Dalfred\Service\ModelCapacityRegistry;
use Dalfred\Service\ThreadService;

// Security checks
if (!$user || !$user->id) {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'Authentification requise']));
}

if (!isModEnabled('dalfred')) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Module Dalfred non activé']));
}

if (!$user->hasRight('dalfred', 'use')) {
    http_response_code(403);
    die(json_encode(['success' => false, 'error' => 'Accès non autorisé']));
}

$threadId = (string) GETPOST('thread_id', 'alphanohtml');

// Empty thread → zero-state response (avoids a useless DB hit on first load).
if ($threadId === '') {
    echo json_encode([
        'success'            => true,
        'input_tokens'       => 0,
        'output_tokens'      => 0,
        'total_tokens'       => 0,
        'last_input_tokens'  => 0,
        'last_output_tokens' => 0,
        'context_used'       => 0,
        'context_window'     => ModelCapacityRegistry::get(''),
        'percent'            => 0.0,
        'model'              => '',
        'inference_count'    => 0,
    ]);
    exit;
}

try {
    $threadService = new ThreadService($db, (int) $user->id, (int) $conf->entity);

    // Ownership check: prevent IDOR — only return aggregates for threads
    // that exist and belong to the current user in the current entity.
    // ThreadService::threadExists() filters on fk_user AND entity (cf.
    // src/Service/ThreadService.php). Other thread reads in the module
    // follow the same pattern (see getThreadMessages line 263).
    // Foreign/missing threads return the same zero-state shape as the
    // empty-thread branch above, so the badge UI naturally hides itself.
    if (!$threadService->threadExists($threadId)) {
        echo json_encode([
            'success'            => true,
            'input_tokens'       => 0,
            'output_tokens'      => 0,
            'total_tokens'       => 0,
            'last_input_tokens'  => 0,
            'last_output_tokens' => 0,
            'context_used'       => 0,
            'context_window'     => ModelCapacityRegistry::get(''),
            'percent'            => 0.0,
            'model'              => '',
            'inference_count'    => 0,
        ]);
        exit;
    }

    $pdo  = $threadService->getPDO();
    $repo = new TokenUsageRepository($pdo);

    $totals = $repo->getTotalForThread($threadId, (int) $conf->entity);

    // Prefer the last recorded context_window (it follows the model the user
    // actually picked for the most recent inference). Fall back to the static
    // registry if the column is empty (legacy rows or unknown model).
    $window = $totals['last_context_window'] > 0
        ? $totals['last_context_window']
        : ModelCapacityRegistry::get($totals['last_model']);

    $total       = $totals['input_tokens'] + $totals['output_tokens'];
    $contextUsed = $totals['last_input_tokens'] + $totals['last_output_tokens'];
    // Saturation gauge: last call only. Summed input_tokens would double-count
    // the conversation history each turn re-sends to the model.
    $percent     = $window > 0 ? round(100.0 * $contextUsed / $window, 1) : 0.0;

    echo json_encode([
        'success'            => true,
        'input_tokens'       => $totals['input_tokens'],
        'output_tokens'      => $totals['output_tokens'],
        'total_tokens'       => $total,
        'last_input_tokens'  => $totals['last_input_tokens'],
        'last_output_tokens' => $totals['last_output_tokens'],
        'context_used'       => $contextUsed,
        'context_window'     => $window,
        'percent'            => $percent,
        'model'              => $totals['last_model'],
        'inference_count'    => $totals['inference_count'],
    ]);
} catch (\Throwable $e) {
    dol_syslog('[Dalfred] ajax/usage.php error: ' . $e->getMessage(), LOG_ERR);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal error']);
}
