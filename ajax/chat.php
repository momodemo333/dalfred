<?php
/**
 * Dalfred AJAX Chat Handler
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
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Failed to load Dolibarr environment']);
    exit;
}

// Load autoloader for Dalfred classes
require_once dol_buildpath('/dalfred/vendor/autoload.php');

use Dalfred\Agent\DalfredAgent;
use Dalfred\Service\AsyncResponseService;
use Dalfred\Service\CommandResolver;
use Dalfred\Service\ConfigService;
use Dalfred\Service\FileAttachmentService;
use Dalfred\Service\ThreadService;
use NeuronAI\Chat\Messages\UserMessage;

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

/**
 * Expand a /command message in place, or return an error response and exit
 * if the command is unknown. The built-in /help command is handled inline.
 *
 * Returns the (possibly modified) $message string. Side effect: when the
 * command is unknown OR when /help fires, this function emits a JSON
 * response and exit()s — the caller never returns from those branches.
 */
function dalfred_expand_command(string $message, \DoliDB $db, \User $user, \Conf $conf): string
{
    $resolver = new CommandResolver($db);
    $parsed = $resolver->parse($message);
    if ($parsed === null) {
        return $message;
    }

    // Built-in /help — list commands without hitting the LLM.
    if ($parsed['name'] === 'help') {
        $cmds = $resolver->listAvailable((int) $user->id, (int) $conf->entity, null, 100);
        $body = "**Commandes disponibles**\n\n";
        if (empty($cmds)) {
            $body .= "_Aucune commande pour le moment._ Demande à Dalfred d'en créer une (\"crée une commande /xxx qui...\") ou utilise la page **Connaissances** dans l'admin.";
        } else {
            foreach ($cmds as $c) {
                $scopeBadge = $c['scope'] === 'shared' ? ' _(partagée)_' : '';
                $body .= "- `/" . $c['name'] . "` — " . $c['title'] . $scopeBadge . "\n";
            }
            $body .= "\nTape `/` au début d'un message pour les utiliser.";
        }

        echo json_encode([
            'success' => true,
            'message' => $body,
            'thread_id' => (string) GETPOST('thread_id', 'alphanohtml'),
            'attachment_errors' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cmd = $resolver->resolve($parsed['name'], (int) $user->id, (int) $conf->entity);
    if ($cmd === null) {
        echo json_encode([
            'success' => false,
            'error' => "Commande inconnue : /" . $parsed['name'] . ". Tape /help pour voir la liste.",
            'status' => 'unknown_command',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $expanded = $cmd['content'];
    if ($parsed['args'] !== '') {
        $expanded .= "\n\nArguments fournis : " . $parsed['args'];
    }
    return $expanded;
}

// Get parameters — branch on Content-Type to handle multipart uploads
$attachmentErrors = [];
$ingestedFiles    = [];
$attachSvc        = null;

$contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
if (str_starts_with($contentType, 'multipart/form-data')) {
    // === MULTIPART BRANCH: file attachments ===
    $message     = (string) GETPOST('message', 'restricthtml', 2);
    $message     = dalfred_expand_command($message, $db, $user, $conf);
    $threadId    = (string) GETPOST('thread_id', 'alphanohtml');
    $contextJson = GETPOST('context', 'restricthtml');

    if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'] ?? null)) {
        $configService = new ConfigService($db, (int) $conf->entity);

        if (!$configService->isAttachmentsEnabled()) {
            $attachmentErrors[] = 'Pièces jointes désactivées par l\'administrateur';
        } else {
            $attachSvc = new FileAttachmentService();
            $provider  = $configService->getProvider();
            $model     = $configService->getModel();

            $count = count($_FILES['attachments']['name']);
            if ($count > FileAttachmentService::MAX_FILES_PER_MSG) {
                $attachmentErrors[] = 'Maximum ' . FileAttachmentService::MAX_FILES_PER_MSG . ' fichiers par message';
                $count = FileAttachmentService::MAX_FILES_PER_MSG;
            }

            for ($i = 0; $i < $count; $i++) {
                $upload = [
                    'name'     => $_FILES['attachments']['name'][$i],
                    'type'     => $_FILES['attachments']['type'][$i],
                    'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                    'error'    => $_FILES['attachments']['error'][$i],
                    'size'     => $_FILES['attachments']['size'][$i],
                ];
                $err = $attachSvc->validateUploadedFile($upload, $provider, $model);
                if ($err !== null) {
                    $attachmentErrors[] = $err;
                    continue;
                }
                try {
                    $ingestedFiles[] = $attachSvc->moveToStorage($upload, $threadId);
                } catch (\Throwable $e) {
                    $msg = $upload['name'] . ' : ' . $e->getMessage();
                    $attachmentErrors[] = $msg;
                    // Async mode closes the HTTP connection before the JSON
                    // response is built, so attachment_errors[] never reaches
                    // the user. Log every failure server-side so admins can
                    // diagnose silent breakage from documents/dalfred/attachments
                    // permissions, full disk, etc.
                    dol_syslog('[Dalfred] Attachment storage failed: ' . $msg, LOG_ERR);
                }
            }
            if ($ingestedFiles !== []) {
                dol_syslog(
                    '[Dalfred] Attachments ingested: ' . count($ingestedFiles) . ' file(s) for thread=' . $threadId,
                    LOG_INFO
                );
            }
        }
    }
} else {
    // === JSON / FORM BRANCH (existing behavior — unchanged) ===
    $message     = GETPOST('message', 'restricthtml');
    $message     = dalfred_expand_command($message, $db, $user, $conf);
    $threadId    = GETPOST('thread_id', 'alphanohtml');
    $contextJson = GETPOST('context', 'restricthtml');
}

// Validate message
if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Message vide']);
    exit;
}

// Parse context
$context = [];
if (!empty($contextJson)) {
    $context = json_decode($contextJson, true) ?: [];
}

try {
    // Release session lock before LLM call to avoid blocking other Dolibarr pages
    // Pattern validated in htdocs/core/ajax/check_notifications.php:128
    session_write_close();

    // Create agent from configuration
    $agent = DalfredAgent::createFromConfig($db, $user->id, $conf->entity);

    // Pass page context to agent for system prompt injection
    if (!empty($context)) {
        $agent->setPageContext($context);
    }

    // Get or create thread
    if (!empty($threadId)) {
        $agent->withThread($threadId);
    } else {
        $threadId = $agent->createThread(
            substr($message, 0, 100),  // Use first 100 chars of message as title
            $context['current_page'] ?? null
        );
    }

    // Async response service
    $asyncService = new AsyncResponseService($db, $user->id, $conf->entity);

    // Auto-recover from stale state (previous run crashed without releasing the lock,
    // e.g. fatal Error not caught by \Exception handler). resetIfStale() is a no-op
    // if the thread is not pending or is still within the timeout window.
    $asyncService->resetIfStale($threadId);

    // Block double-submit: if thread is genuinely still processing, reject
    if ($asyncService->isProcessing($threadId)) {
        echo json_encode([
            'success' => false,
            'error' => 'Un message est déjà en cours de traitement. Veuillez patienter.',
            'status' => 'already_processing',
            'thread_id' => $threadId
        ]);
        exit;
    }

    // Determine execution mode: async (fire-and-forget) or sync (fallback)
    if ($asyncService->canRunInBackground()) {
        // === ASYNC MODE ===
        dol_syslog('[Dalfred] Async mode: fire-and-forget for thread=' . $threadId, LOG_INFO);

        // Mark thread as processing
        $asyncService->markProcessing($threadId);

        // Close the HTTP connection — client receives this immediately
        $asyncService->closeConnectionAndContinue([
            'status' => 'processing',
            'thread_id' => $threadId
        ]);

        // --- From here, client has received the response ---
        // --- PHP continues executing in the background ---

        try {
            // Build UserMessage — with ContentBlocks when files were attached, plain string otherwise
            if ($ingestedFiles !== []) {
                if ($attachSvc === null) {
                    $attachSvc = new FileAttachmentService();
                }
                $userMessage = new UserMessage($attachSvc->buildContentBlocks($ingestedFiles, $message, $provider, $model));
            } else {
                $userMessage = new UserMessage($message);
            }

            // Send message and get response
            $llmResponse = $agent->chat($userMessage)->getMessage();

            // Extract text content
            $responseText = extractResponseText($llmResponse);

            // Mark complete
            $asyncService->markComplete($threadId);
            dol_syslog('[Dalfred] Async: LLM response received for thread=' . $threadId, LOG_INFO);
        } catch (\Throwable $bgError) {
            // Catch \Throwable (not just \Exception) so PHP Errors like TypeError
            // (e.g. NeuronAI Tool::getResult() returning null) also release the lock.
            $userError = mapErrorToUserMessage($bgError->getMessage());
            $asyncService->markError($threadId, $userError);
            dol_syslog('[Dalfred] Async: LLM error for thread=' . $threadId . ': ' . $bgError->getMessage(), LOG_ERR);
        }
    } else {
        // === SYNC MODE (fallback) ===
        dol_syslog('[Dalfred] Sync mode (fallback) for thread=' . $threadId, LOG_INFO);

        // Build UserMessage — with ContentBlocks when files were attached, plain string otherwise
        if ($ingestedFiles !== []) {
            if ($attachSvc === null) {
                $attachSvc = new FileAttachmentService();
            }
            $userMessage = new UserMessage($attachSvc->buildContentBlocks($ingestedFiles, $message, $provider, $model));
        } else {
            $userMessage = new UserMessage($message);
        }

        // Send message and get response
        $llmResponse = $agent->chat($userMessage)->getMessage();

        // Extract text content
        $responseText = extractResponseText($llmResponse);

        echo json_encode([
            'success'           => true,
            'message'           => $responseText,
            'thread_id'         => $threadId,
            'attachment_errors' => $attachmentErrors,
        ]);
    }

} catch (\Throwable $e) {
    // Catch \Throwable to also handle PHP Errors (TypeError, etc.) that would
    // otherwise leave the thread locked in pending_response = 1.
    dol_syslog('[Dalfred] Chat error: ' . $e->getMessage(), LOG_ERR);

    // If we were in async mode and marked processing, mark error
    if (isset($asyncService, $threadId) && $asyncService->isProcessing($threadId)) {
        $asyncService->markError($threadId, mapErrorToUserMessage($e->getMessage()));
    }

    $userError = mapErrorToUserMessage($e->getMessage());

    echo json_encode([
        'success' => false,
        'error' => $userError,
        'debug' => getDolGlobalInt('DALFRED_DEBUG') ? $e->getMessage() : null
    ]);
}

/**
 * Extract text content from a NeuronAI response object
 */
function extractResponseText($response): string
{
    $text = '';

    if (is_string($response)) {
        $text = $response;
    } elseif (is_object($response)) {
        if (method_exists($response, 'getContent')) {
            $text = $response->getContent();
        } elseif (method_exists($response, 'getMessage')) {
            $text = $response->getMessage();
        } elseif (method_exists($response, '__toString')) {
            $text = (string) $response;
        } elseif (isset($response->content)) {
            $text = $response->content;
        } elseif (isset($response->message)) {
            $text = $response->message;
        }
    }

    if (empty($text)) {
        return "Je suis désolé, je n'ai pas pu générer une réponse. Pouvez-vous reformuler votre question ?";
    }

    // Safety filter: detect when the LLM outputs a tool name as text instead of
    // making a proper function call. Tool names follow the pattern "word_word" or
    // "word_word_word" (snake_case, no spaces, no punctuation).
    $trimmed = trim($text);
    if (preg_match('/^[a-z][a-z0-9]*(_[a-z0-9]+)+$/', $trimmed) && strlen($trimmed) < 80) {
        dol_syslog('[Dalfred] Tool name leaked as text response: ' . $trimmed, LOG_WARNING);
        return "Je traite votre demande, veuillez patienter un instant...";
    }

    return $text;
}

/**
 * Map technical error messages to user-friendly messages
 */
function mapErrorToUserMessage(string $errorMessage): string
{
    if (strpos($errorMessage, 'API key') !== false) {
        return 'La clé API n\'est pas configurée. Contactez l\'administrateur.';
    }
    if (strpos($errorMessage, 'rate limit') !== false || preg_match('/\b429\b/', $errorMessage)) {
        return 'Trop de requêtes. Veuillez patienter quelques instants.';
    }
    if (strpos($errorMessage, 'timeout') !== false) {
        return 'La requête a pris trop de temps. Veuillez réessayer.';
    }
    if (strpos($errorMessage, 'No messages in the chat history') !== false) {
        return 'La conversation est devenue trop longue. Veuillez démarrer une nouvelle conversation.';
    }

    // API provider overloaded or unavailable (529 Anthropic, 503 any provider)
    if (strpos($errorMessage, 'overloaded') !== false || preg_match('/\b529\b/', $errorMessage)) {
        return 'Le service IA est actuellement surchargé. Veuillez réessayer dans quelques minutes ou changer de modèle dans les paramètres.';
    }
    if (preg_match('/\b50[234]\b/', $errorMessage) || strpos($errorMessage, 'Service Unavailable') !== false || strpos($errorMessage, 'Bad Gateway') !== false) {
        return 'Le service IA est temporairement indisponible. Veuillez réessayer dans quelques instants.';
    }

    // Authentication/authorization errors from provider
    if (preg_match('/\b401\b/', $errorMessage) && strpos($errorMessage, 'api.') !== false) {
        return 'La clé API est invalide ou expirée. Contactez l\'administrateur.';
    }
    if (preg_match('/\b403\b/', $errorMessage) && strpos($errorMessage, 'api.') !== false) {
        return 'L\'accès au service IA est refusé. Vérifiez la configuration de la clé API.';
    }

    return 'Une erreur est survenue lors du traitement de votre message.';
}
