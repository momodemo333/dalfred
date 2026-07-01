<?php
/**
 * Dalfred AJAX Thread Handler
 *
 * @package    Dalfred
 * @author     E-dem
 * @version    1.0.0
 * @license    GPL-3.0+
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
if (!defined('NOSCANPOSTFORINJECTION')) define('NOSCANPOSTFORINJECTION', 1);

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

// Load autoloader for Dalfred classes
require_once dol_buildpath('/dalfred/vendor/autoload.php');

use Dalfred\Service\AsyncResponseService;
use Dalfred\Service\ThreadService;

// Initialize response array
$response = [
    'success' => false,
    'error' => null,
    'timestamp' => date('c')
];

// Security checks
if (!$user || !$user->id) {
    http_response_code(401);
    $response['error'] = 'Authentification requise';
    die(json_encode($response));
}

// Check if module is enabled
if (!isModEnabled('dalfred')) {
    http_response_code(403);
    $response['error'] = 'Module Dalfred non activé';
    die(json_encode($response));
}

// Check user permissions
if (!$user->hasRight('dalfred', 'use')) {
    http_response_code(403);
    $response['error'] = 'Accès non autorisé';
    die(json_encode($response));
}

// Get action and parameters
$action = GETPOST('action', 'alphanohtml');
$threadId = GETPOST('thread_id', 'alphanohtml');

try {
    $threadService = new ThreadService($db, $user->id, $conf->entity);

    switch ($action) {
        case 'active':
            // Get user's current active thread (for restoring conversation after page refresh)
            $activeThread = $threadService->getActiveThread();

            if ($activeThread) {
                echo json_encode([
                    'success' => true,
                    'thread' => $activeThread
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'thread' => null,
                    'message' => 'No active thread'
                ]);
            }
            break;

        case 'reopen':
            // Reopen a previously archived thread
            if (empty($threadId)) {
                echo json_encode(['success' => false, 'error' => 'Thread ID requis']);
                exit;
            }

            $result = $threadService->reopenThread($threadId);

            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Thread réouvert' : 'Erreur lors de la réouverture',
                'thread_id' => $threadId
            ]);
            break;

        case 'history':
            // Get chat history for a thread
            if (empty($threadId)) {
                echo json_encode(['success' => false, 'error' => 'Thread ID requis']);
                exit;
            }

            $messages = $threadService->getThreadMessages($threadId);

            // Format messages for display
            $formattedMessages = [];
            foreach ($messages as $msg) {
                $formattedMessages[] = [
                    'role' => $msg['role'],
                    'content' => $msg['content'],
                    'formatted_time' => date('H:i', strtotime($msg['created_at'])),
                    // Preserve attachment metadata so the chat UI can re-render
                    // file chips on history reload (chips were stripped before
                    // 2.12.3, making attachments disappear on page refresh).
                    'attachments' => $msg['attachments'] ?? [],
                ];
            }

            echo json_encode([
                'success' => true,
                'messages' => $formattedMessages,
                'thread_id' => $threadId
            ]);
            break;

        case 'close':
            // Close/archive a thread
            if (empty($threadId)) {
                echo json_encode(['success' => false, 'error' => 'Thread ID requis']);
                exit;
            }

            // Block close if thread is currently processing
            $asyncCheck = new AsyncResponseService($db, $user->id, $conf->entity);
            if ($asyncCheck->isProcessing($threadId)) {
                echo json_encode(['success' => false, 'error' => 'Impossible de fermer : un message est en cours de traitement.']);
                exit;
            }

            $result = $threadService->archiveThread($threadId);

            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Thread fermé' : 'Erreur lors de la fermeture'
            ]);
            break;

        case 'delete':
            // Delete a thread (requires manage permission)
            if (!$user->hasRight('dalfred', 'threads', 'manage')) {
                echo json_encode(['success' => false, 'error' => 'Permission refusée']);
                exit;
            }

            if (empty($threadId)) {
                echo json_encode(['success' => false, 'error' => 'Thread ID requis']);
                exit;
            }

            $result = $threadService->deleteThread($threadId);

            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Thread supprimé' : 'Erreur lors de la suppression'
            ]);
            break;

        case 'list':
            // List user's threads
            $limit = GETPOST('limit', 'int') ?: 20;
            $status = GETPOST('status', 'alphanohtml') ?: 'all';

            $threads = $threadService->getUserThreads($limit, $status);

            // Format threads for display
            $formattedThreads = [];
            foreach ($threads as $thread) {
                $formattedThreads[] = [
                    'thread_id' => $thread['thread_id'],
                    'title' => $thread['title'] ?: 'Conversation sans titre',
                    'status' => $thread['status'],
                    'last_message' => date('d/m/Y H:i', strtotime($thread['last_message_at'])),
                    'created' => date('d/m/Y', strtotime($thread['created_at']))
                ];
            }

            echo json_encode([
                'success' => true,
                'threads' => $formattedThreads
            ]);
            break;

        case 'create':
            // Create a new thread
            $title = GETPOST('title', 'restricthtml') ?: null;
            $contextPage = GETPOST('context_page', 'restricthtml') ?: null;

            $newThreadId = $threadService->createThread($title, $contextPage);

            echo json_encode([
                'success' => true,
                'thread_id' => $newThreadId
            ]);
            break;

        case 'poll':
            // Lightweight endpoint to check if async response is ready
            session_write_close(); // Don't block session for polling

            if (empty($threadId)) {
                echo json_encode(['success' => false, 'error' => 'Thread ID requis']);
                exit;
            }

            $asyncService = new AsyncResponseService($db, $user->id, $conf->entity);
            $state = $asyncService->getPendingState($threadId);

            if (!$state['pending']) {
                if (!empty($state['error'])) {
                    // LLM returned an error
                    echo json_encode([
                        'success' => true,
                        'status' => 'error',
                        'error' => $state['error'],
                        'thread_id' => $threadId
                    ]);
                } else {
                    // Response ready — fetch the last assistant message
                    $messages = $threadService->getThreadMessages($threadId);
                    $lastMessage = '';
                    // Find last assistant message
                    for ($i = count($messages) - 1; $i >= 0; $i--) {
                        if ($messages[$i]['role'] === 'assistant') {
                            $lastMessage = $messages[$i]['content'];
                            break;
                        }
                    }

                    echo json_encode([
                        'success' => true,
                        'status' => 'complete',
                        'message' => $lastMessage,
                        'thread_id' => $threadId
                    ]);
                }
            } else {
                // Still processing — check for stale timeout
                $asyncService->resetIfStale($threadId);

                // Re-read state after potential reset
                $state = $asyncService->getPendingState($threadId);

                if (!$state['pending'] && !empty($state['error'])) {
                    // Was stale, now marked as error
                    echo json_encode([
                        'success' => true,
                        'status' => 'error',
                        'error' => $state['error'],
                        'thread_id' => $threadId
                    ]);
                } else {
                    echo json_encode([
                        'success' => true,
                        'status' => 'processing',
                        'since' => $state['since'],
                        'thread_id' => $threadId
                    ]);
                }
            }
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
    }

} catch (Exception $e) {
    dol_syslog('[Dalfred] Thread error: ' . $e->getMessage(), LOG_ERR);

    echo json_encode([
        'success' => false,
        'error' => 'Une erreur est survenue',
        'debug' => getDolGlobalInt('DALFRED_DEBUG') ? $e->getMessage() : null
    ]);
}
