<?php
/**
 * Dalfred Full Screen Chat Interface
 *
 * @package    Dalfred
 * @author     E-dem
 * @version    1.0.0
 * @license    GPL-3.0+
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../main.inc.php")) {
    $res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Main include failed");
}

// Load language files
$langs->loadLangs(array("admin", "dalfred@dalfred"));

// Security check
if (!$user->hasRight('dalfred', 'use')) {
    accessforbidden();
}

// Check AI configuration via centralized ConfigService
require_once __DIR__.'/vendor/autoload.php';
$configService = new \Dalfred\Service\ConfigService($db, $conf->entity);
if (!$configService->isApiKeyConfigured()) {
    setEventMessages($langs->trans("APIKeyNotConfigured"), null, 'errors');
    header('Location: ' . dol_buildpath('/dalfred/admin/ai_setup.php', 1));
    exit;
}

// Get thread_id from URL if provided
$threadId = GETPOST('thread_id', 'alphanohtml');

// Optional prefill for the chat input (e.g. ?prefill=/my-command)
$prefill = GETPOST('prefill', 'restricthtml');

// Page title
$title = $langs->trans("Chat") . ' - Dalfred';
$help_url = '';

// Standard Dolibarr header
llxHeader('', $title, $help_url, '', 0, 0, array(
	'/dalfred/js/lib/marked.min.js',
	'/dalfred/js/lib/purify.min.js',
	'/dalfred/js/dalfred-icons.js',
	'/dalfred/js/dalfred-markdown.js',
	'/dalfred/js/dalfred-attachments.js',
	'/dalfred/js/dalfred-copyable.js',
	'/dalfred/js/dalfred.js'
), array('/dalfred/css/dalfred.css'));

// Title
$brandingService = new \Dalfred\Service\BrandingService();
print '<style>' . $brandingService->getCssVariables() . '</style>';

// Token usage badge styles — kept inline (next to brand vars) so the badge
// renders identically even when /css/dalfred.css is cached or stale on
// upgrade. Lightweight and scoped via .dalfred-token-badge.
print '<style>
.dalfred-token-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    color: #666;
    margin-top: 4px;
    margin-left: auto;
    padding: 2px 8px;
    border-radius: 10px;
    background: rgba(0,0,0,0.03);
    cursor: help;
    user-select: none;
    white-space: pre-line;
}
.dalfred-token-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #4a90e2;
}
.dalfred-token-dot[data-level="low"]      { background: #4a90e2; }
.dalfred-token-dot[data-level="medium"]   { background: #f5a623; }
.dalfred-token-dot[data-level="high"]     { background: #f57c00; }
.dalfred-token-dot[data-level="critical"] { background: #d0021b; }
.dalfred-token-badge-row {
    display: flex;
    justify-content: flex-end;
    padding: 0 4px;
}
</style>';
// Attachments: respect admin toggle AND provider capability for images.
$attachmentsEnabled = $configService->isAttachmentsEnabled();
$accepted = ($attachmentsEnabled && \Dalfred\Service\ProviderCapabilities::supportsImages($configService->getProvider(), $configService->getModel()))
    ? '.txt,.md,.log,.csv,.jpg,.jpeg,.png,.gif,.webp,.pdf'
    : '.txt,.md,.log,.csv,.pdf';
print '<script>window.DalfredConfig = '
    . json_encode([
        'agentName'                 => $brandingService->getName(),
        'agentIconHtml'             => $brandingService->getAgentIconHtml('dalfred-icon-md'),
        'attachmentsEnabled'        => $attachmentsEnabled,
        'attachmentsAcceptedTypes'  => $attachmentsEnabled ? $accepted : '',
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)
    . ';</script>';
print load_fiche_titre(
    '<span style="font-size: 28px; margin-right: 10px; vertical-align: middle;">'
    . $brandingService->getAgentIconHtml('dalfred-icon-md')
    . '</span> '
    . dol_escape_htmltag($brandingService->getName()) . ' - ' . $langs->trans("Chat"),
    '', ''
);

// Start content
print '<div class="fichecenter">';

?>

<div class="dalfred-fullscreen-container">
    <!-- Header -->
    <div class="dalfred-fullscreen-header">
        <h1>
            <?php echo $brandingService->getAgentIconHtml('dalfred-icon-md'); ?>
            <?php echo dol_escape_htmltag($brandingService->getName()); ?>
        </h1>

        <div class="dalfred-fullscreen-actions">
            <a href="<?php echo dol_buildpath('/dalfred/knowledge.php', 1); ?>" class="dalfred-btn">
                🧠 <?php echo $langs->trans("KnowledgeMemory"); ?>
            </a>

            <button class="dalfred-btn" onclick="clearFullscreenChat()">
                🗑️ <?php echo $langs->trans("NewConversation"); ?>
            </button>

            <a href="<?php echo dol_buildpath('/dalfred/threads.php', 1); ?>" class="dalfred-btn">
                📋 <?php echo $langs->trans("Conversations"); ?>
            </a>
        </div>
    </div>

    <!-- Main chat area -->
    <div class="dalfred-fullscreen-body">
        <div class="dalfred-fullscreen-main">
            <!-- Messages area -->
            <div class="dalfred-fullscreen-messages" id="fullscreen-messages">
                <!-- Welcome message -->
                <div class="dalfred-welcome-screen" id="welcome-message">
                    <h2>👋 <?php echo sprintf($langs->trans("WelcomeMessage"), $user->firstname ?: $user->login); ?></h2>
                    <p><?php echo $langs->trans("WelcomeIntro", dol_escape_htmltag($brandingService->getName())); ?></p>

                    <div class="dalfred-suggestions">
                        <div class="dalfred-suggestion" onclick="sendSuggestion(DALFRED_I18N.suggestionUnpaidInvoices)">
                            💰 <?php echo $langs->trans("SuggestionLabelUnpaidInvoices"); ?>
                        </div>
                        <div class="dalfred-suggestion" onclick="sendSuggestion(DALFRED_I18N.suggestionMonthlyRevenue)">
                            📊 <?php echo $langs->trans("SuggestionLabelMonthlyRevenue"); ?>
                        </div>
                        <div class="dalfred-suggestion" onclick="sendSuggestion(DALFRED_I18N.suggestionRecentClients)">
                            👥 <?php echo $langs->trans("SuggestionLabelRecentClients"); ?>
                        </div>
                        <div class="dalfred-suggestion" onclick="sendSuggestion(DALFRED_I18N.suggestionCreateInvoice)">
                            ➕ <?php echo $langs->trans("SuggestionLabelCreateInvoice"); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Typing indicator -->
            <div class="dalfred-fullscreen-typing" id="typing-indicator">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div class="dalfred-fullscreen-avatar" style="background: var(--dalfred-primary, #4CAF50); color: white;"><?php echo $brandingService->getAgentIconHtml('dalfred-icon-sm'); ?></div>
                    <div style="display: flex; gap: 4px;">
                        <div class="dalfred-typing-dot"></div>
                        <div class="dalfred-typing-dot"></div>
                        <div class="dalfred-typing-dot"></div>
                    </div>
                </div>
            </div>

            <!-- Input area -->
            <div class="dalfred-fullscreen-input-area">
                <!-- Attachments preview/errors zones live INSIDE the input area
                     so they share its white background and padding context. -->
                <div id="fullscreen-attach-errors" class="dalfred-attach-errors" style="display:none;"></div>
                <div id="fullscreen-attach-preview" class="dalfred-attach-preview" style="display:none;"></div>

                <div class="dalfred-fullscreen-input-wrapper">
                    <?php if ($attachmentsEnabled): ?>
                        <button type="button" id="fullscreen-attach-btn" class="dalfred-attach-btn" title="<?php echo dol_escape_htmltag($langs->trans('AttachmentButton') ?: 'Joindre un fichier'); ?>">
                            <?php echo \Dalfred\Icon::render('paperclip'); ?>
                        </button>
                    <?php endif; ?>
                    <textarea
                        id="fullscreen-input"
                        class="dalfred-fullscreen-input"
                        placeholder="<?php echo $langs->trans("TypeYourMessage"); ?>..."
                        rows="1"
                    ></textarea>
                    <button id="fullscreen-send" class="dalfred-fullscreen-send">
                        ➤
                    </button>
                </div>

                <!-- Token usage badge (refreshed after each agent reply) -->
                <div class="dalfred-token-badge-row">
                    <div class="dalfred-token-badge" id="dalfred-token-badge"
                         data-thread-id=""
                         title=""
                         style="display:none;">
                        <span class="dalfred-token-dot" data-level="low"></span>
                        <span class="dalfred-token-text">—</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Translations for fullscreen chat
var DALFRED_BASE_URL = <?php echo json_encode(dol_buildpath('/dalfred/', 1)); ?>;
var DALFRED_I18N = <?php echo json_encode(array(
    'welcomeTitle' => sprintf($langs->transnoentities("WelcomeMessage"), $user->firstname ?: $user->login),
    'welcomeIntro' => $langs->transnoentities("WelcomeIntro", $brandingService->getName()),
    'suggestionUnpaidInvoices' => $langs->transnoentities("SuggestionUnpaidInvoices"),
    'suggestionMonthlyRevenue' => $langs->transnoentities("SuggestionMonthlyRevenue"),
    'suggestionRecentClients' => $langs->transnoentities("SuggestionRecentClients"),
    'suggestionCreateInvoice' => $langs->transnoentities("SuggestionCreateInvoice"),
    'suggestionLabelUnpaidInvoices' => $langs->transnoentities("SuggestionLabelUnpaidInvoices"),
    'suggestionLabelMonthlyRevenue' => $langs->transnoentities("SuggestionLabelMonthlyRevenue"),
    'suggestionLabelRecentClients' => $langs->transnoentities("SuggestionLabelRecentClients"),
    'suggestionLabelCreateInvoice' => $langs->transnoentities("SuggestionLabelCreateInvoice"),
    'confirmClear' => $langs->transnoentities("ConfirmClearConversation"),
    'errorServer' => $langs->tab_translate["ErrorServer"] ?? 'Server error (%s)',
    'errorInvalidResponse' => $langs->transnoentities("ErrorInvalidResponse"),
    'errorGeneric' => $langs->transnoentities("ErrorGeneric"),
    'errorNetwork' => $langs->transnoentities("ErrorNetwork"),
)); ?>;

// Fullscreen chat functionality
var fullscreenThreadId = <?php echo $threadId ? '"'.htmlspecialchars($threadId).'"' : 'null'; ?>;
var isTyping = false;

// === Token usage badge ===
// Light UI under the textarea that shows "X / Y tokens (P%)" for the current
// thread. Refreshed after each agent reply via window.dalfredRefreshTokenBadge.
// Hides gracefully when the AJAX call fails, the thread is empty, or the
// endpoint returns inference_count===0 (e.g. thread not owned — IDOR safety,
// see ajax/usage.php).
(function () {
    var badge = document.getElementById('dalfred-token-badge');
    if (!badge) return;
    var dot  = badge.querySelector('.dalfred-token-dot');
    var text = badge.querySelector('.dalfred-token-text');
    var LABEL_TEMPLATE = <?php echo json_encode($langs->transnoentities('TokenBadgeLabel', '__T__', '__W__', '__P__')); ?>;

    function fmt(n) {
        n = Number(n) || 0;
        if (n >= 1000) return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k';
        return String(n);
    }

    function levelFor(pct) {
        if (pct >= 90) return 'critical';
        if (pct >= 75) return 'high';
        if (pct >= 50) return 'medium';
        return 'low';
    }

    window.dalfredRefreshTokenBadge = function (threadId) {
        if (!threadId) {
            badge.style.display = 'none';
            return;
        }
        try {
            var url = <?php echo json_encode(dol_buildpath('/dalfred/ajax/usage.php', 1)); ?>
                + '?thread_id=' + encodeURIComponent(threadId);
            fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d || !d.success || !d.inference_count) {
                        badge.style.display = 'none';
                        return;
                    }
                    badge.style.display = 'inline-flex';
                    badge.setAttribute('data-thread-id', threadId);
                    dot.setAttribute('data-level', levelFor(d.percent));
                    // Visible label = current-context saturation. Cumulative
                    // input+output cannot be compared to the window because the
                    // history is re-sent every turn (would double-count).
                    text.textContent = LABEL_TEMPLATE
                        .replace('__T__', fmt(d.context_used))
                        .replace('__W__', fmt(d.context_window))
                        .replace('__P__', String(d.percent));
                    badge.setAttribute('title',
                        <?php echo json_encode($langs->transnoentities('TokenBadgeTooltip')); ?>
                        + '\n' + <?php echo json_encode($langs->transnoentities('TokenBadgeContextCurrent', '__C__', '__W__', '__P__')); ?>
                            .replace('__C__', d.context_used)
                            .replace('__W__', d.context_window)
                            .replace('__P__', String(d.percent))
                        + '\n' + <?php echo json_encode($langs->transnoentities('TokenBadgeLastCall', '__I__', '__O__')); ?>
                            .replace('__I__', d.last_input_tokens)
                            .replace('__O__', d.last_output_tokens)
                        + '\n' + <?php echo json_encode($langs->transnoentities('TokenBadgeModel', '__M__', '__W__')); ?>
                            .replace('__M__', d.model || '?')
                            .replace('__W__', d.context_window)
                        + '\n' + d.inference_count + ' '
                        + <?php echo json_encode(strtolower(trim(str_replace('%s', '', $langs->transnoentities('TokenBadgeExchanges'))))); ?>
                        + '\n' + <?php echo json_encode($langs->transnoentities('TokenBadgeCumulative', '__T__')); ?>
                            .replace('__T__', d.total_tokens)
                    );
                })
                .catch(function () { badge.style.display = 'none'; });
        } catch (e) {
            badge.style.display = 'none';
        }
    };
})();

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    initFullscreenChat();
});

async function initFullscreenChat() {
    // Restore thread from floating chat if available
    if (!fullscreenThreadId && window.dalfredChat && window.dalfredChat.threadId) {
        fullscreenThreadId = window.dalfredChat.threadId;
    }

    // Try to restore from sessionStorage
    if (!fullscreenThreadId) {
        try {
            const stateData = sessionStorage.getItem('dalfred_chat_state');
            if (stateData) {
                const state = JSON.parse(stateData);
                if (state.threadId) {
                    fullscreenThreadId = state.threadId;
                }
            }
        } catch (e) {
            console.warn('Could not restore thread from sessionStorage:', e);
        }
    }

    // If still no thread, fetch active thread from server
    if (!fullscreenThreadId) {
        try {
            const response = await fetch(DALFRED_BASE_URL + 'ajax/thread.php?action=active&token=notrequired', {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const data = await response.json();
            if (data.success && data.thread) {
                fullscreenThreadId = data.thread.thread_id;
                // Save to sessionStorage
                sessionStorage.setItem('dalfred_chat_state', JSON.stringify({
                    threadId: fullscreenThreadId,
                    isOpen: true,
                    timestamp: Date.now()
                }));
            }
        } catch (e) {
            console.warn('Could not fetch active thread from server:', e);
        }
    }

    // Check if we need to resume polling (user navigated away during async processing)
    var shouldResumePoll = false;
    try {
        const stateData = sessionStorage.getItem('dalfred_chat_state');
        if (stateData) {
            const state = JSON.parse(stateData);
            if (state.polling && state.threadId) {
                shouldResumePoll = true;
                fullscreenThreadId = state.threadId;
            }
        }
    } catch (e) {}

    // Load history if we have a thread
    if (fullscreenThreadId) {
        await loadFullscreenHistory();
    }

    // Resume polling if needed
    if (shouldResumePoll) {
        showTyping();
        pollForFullscreenResponse(fullscreenThreadId);
    }

    // Bind events
    const input = document.getElementById('fullscreen-input');
    const sendBtn = document.getElementById('fullscreen-send');

    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendFullscreenMessage();
        }
    });

    input.addEventListener('input', autoResizeTextarea);
    sendBtn.addEventListener('click', sendFullscreenMessage);

    // Focus input
    input.focus();

    // Wire the attachments module if enabled (mirrors widget logic in dalfred.js).
    if (window.DalfredConfig && window.DalfredConfig.attachmentsEnabled && window.DalfredAttachments) {
        var attachBtn = document.getElementById('fullscreen-attach-btn');
        var previewZone = document.getElementById('fullscreen-attach-preview');
        var errorsZone = document.getElementById('fullscreen-attach-errors');
        if (attachBtn && previewZone) {
            DalfredAttachments.attachToInput(attachBtn, previewZone, errorsZone, {
                accept: window.DalfredConfig.attachmentsAcceptedTypes || '.txt,.md,.log,.csv,.jpg,.jpeg,.png,.gif,.webp'
            });
        }
        // Drag & drop on the whole fullscreen chat container.
        var fsContainer = document.querySelector('.dalfred-fullscreen-container') || document.body;
        if (fsContainer) {
            fsContainer.setAttribute('data-drop-label', (DALFRED_I18N && DALFRED_I18N.attachDropZone) || 'Déposez vos fichiers ici');
            var accept = window.DalfredConfig.attachmentsAcceptedTypes || '.txt,.md,.log,.csv,.jpg,.jpeg,.png,.gif,.webp';
            DalfredAttachments.enableDragDrop(fsContainer, {
                onDrop: function (files) {
                    DalfredAttachments.addFiles(files, accept);
                }
            });
        }
    }
}

async function loadFullscreenHistory() {
    try {
        const params = new URLSearchParams({
            action: 'history',
            thread_id: fullscreenThreadId,
            token: 'notrequired'
        });

        const response = await fetch(`${DALFRED_BASE_URL}ajax/thread.php?${params}`, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const data = await response.json();

        if (data.success && data.messages && data.messages.length > 0) {
            // Hide welcome message
            const welcomeMessage = document.getElementById('welcome-message');
            if (welcomeMessage) {
                welcomeMessage.style.display = 'none';
            }

            const messagesContainer = document.getElementById('fullscreen-messages');

            data.messages.forEach(msg => {
                addFullscreenMessage(msg.role, msg.content, msg.formatted_time, msg.attachments || []);
            });

            // Refresh the token usage badge for the loaded thread.
            if (window.dalfredRefreshTokenBadge && fullscreenThreadId) {
                window.dalfredRefreshTokenBadge(fullscreenThreadId);
            }
        }
    } catch (error) {
        console.error('Error loading history:', error);
    }
}

async function sendFullscreenMessage() {
    const input = document.getElementById('fullscreen-input');
    const message = input.value.trim();

    if (!message || isTyping) return;

    // Hide welcome message
    const welcomeMessage = document.getElementById('welcome-message');
    if (welcomeMessage) {
        welcomeMessage.style.display = 'none';
    }

    // Build a local-preview payload so the user's bubble shows attached files
    // immediately, mirroring the shape ThreadService::getThreadMessages emits.
    let bubbleAttachments = [];
    if (window.DalfredAttachments) {
        const localFiles = DalfredAttachments.getSelectedFiles();
        bubbleAttachments = localFiles.map(function (f) {
            const isImage = f.type && f.type.indexOf('image/') === 0;
            return {
                type: 'attachment',
                kind: isImage ? 'image' : 'text',
                name: f.name,
                mime: f.type,
                size: f.size,
                url: isImage ? URL.createObjectURL(f) : '#',
                expired: false
            };
        });
    }

    // Add user message (with attachment chips if any)
    addFullscreenMessage('user', message, null, bubbleAttachments);

    // Clear input
    input.value = '';
    autoResizeTextarea();

    // Show typing
    showTyping();

    try {
        const contextData = {
            current_page: window.location.pathname,
            page_title: document.title,
            chat_type: 'fullscreen'
        };

        const hasAttachments = window.DalfredAttachments && DalfredAttachments.getSelectedFiles().length > 0;
        let response;

        if (hasAttachments) {
            // Multipart branch: POST with FormData so the backend can read $_FILES.
            const fd = new FormData();
            fd.append('message', message);
            fd.append('thread_id', fullscreenThreadId || '');
            fd.append('context', JSON.stringify(contextData));
            fd.append('token', 'notrequired');
            const files = DalfredAttachments.getSelectedFiles();
            for (let i = 0; i < files.length; i++) {
                fd.append('attachments[]', files[i]);
            }
            response = await fetch(`${DALFRED_BASE_URL}ajax/chat.php`, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
        } else {
            // Original GET branch (unchanged).
            const params = new URLSearchParams({
                message: message,
                thread_id: fullscreenThreadId || '',
                context: JSON.stringify(contextData),
                token: 'notrequired'
            });
            response = await fetch(`${DALFRED_BASE_URL}ajax/chat.php?${params}`, {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
        }

        if (!response.ok) {
            throw new Error(DALFRED_I18N.errorServer.replace('%s', response.status));
        }

        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            throw new Error(DALFRED_I18N.errorInvalidResponse);
        }

        const data = await response.json();

        // Surface attachment errors (server-side validation failures) inline.
        if (data.attachment_errors && data.attachment_errors.length > 0) {
            const errZone = document.getElementById('fullscreen-attach-errors');
            if (errZone) {
                errZone.style.display = 'block';
                errZone.innerHTML = data.attachment_errors.map(function (e) {
                    var safe = String(e).replace(/[<>&]/g, function (c) {
                        return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c];
                    });
                    return '<div class="dalfred-attach-error">' + safe + '</div>';
                }).join('');
            }
        }
        // Reset selection on any answered request (success or async accepted).
        if (window.DalfredAttachments) {
            DalfredAttachments.clear();
            const prev = document.getElementById('fullscreen-attach-preview');
            if (prev) { prev.style.display = 'none'; prev.innerHTML = ''; }
        }

        if (data.status === 'processing') {
            // Async mode: poll for response
            fullscreenThreadId = data.thread_id;
            saveFullscreenState(true);
            pollForFullscreenResponse(data.thread_id);
        } else if (data.status === 'already_processing') {
            hideTyping();
            addFullscreenMessage('assistant', '❌ ' + (data.error || 'Un message est déjà en cours de traitement.'));
        } else {
            hideTyping();

            if (data.success) {
                fullscreenThreadId = data.thread_id;
                addFullscreenMessage('assistant', data.message);
                syncFloatingChat();
                saveFullscreenState(false);
                // Refresh the token usage badge with the (possibly new) thread id.
                if (window.dalfredRefreshTokenBadge && data.thread_id) {
                    window.dalfredRefreshTokenBadge(data.thread_id);
                }
            } else {
                addFullscreenMessage('assistant', '❌ ' + (data.error || 'Erreur inconnue'));
            }
        }
    } catch (error) {
        hideTyping();

        let userMessage = DALFRED_I18N.errorGeneric;
        if (error.message.includes('Failed to fetch')) {
            userMessage = DALFRED_I18N.errorNetwork;
        } else if (error.message.includes(DALFRED_I18N.errorServer.split('%s')[0])) {
            userMessage = error.message;
        }

        addFullscreenMessage('assistant', '❌ ' + userMessage);
        console.error('Dalfred Error:', error);
    }
}

function addFullscreenMessage(role, content, time, attachments) {
    const messagesContainer = document.getElementById('fullscreen-messages');

    const messageDiv = document.createElement('div');
    messageDiv.className = `dalfred-fullscreen-message ${role}`;

    const avatar = document.createElement('div');
    avatar.className = 'dalfred-fullscreen-avatar';
    avatar.textContent = role === 'user' ? '👤' : '🤖';

    const contentWrapper = document.createElement('div');

    const contentDiv = document.createElement('div');
    contentDiv.className = 'dalfred-fullscreen-content';
    contentDiv.innerHTML = formatMessage(content);

    const timeDiv = document.createElement('div');
    timeDiv.className = 'dalfred-fullscreen-time';
    timeDiv.textContent = time || new Date().toLocaleTimeString(undefined, {hour: '2-digit', minute: '2-digit'});

    contentWrapper.appendChild(contentDiv);

    // Render attachment chips below the message content (same shape as the
    // floating widget: {kind, name, mime, size, url, expired}).
    if (attachments && attachments.length > 0) {
        const box = document.createElement('div');
        box.className = 'dalfred-attach-history';
        for (let i = 0; i < attachments.length; i++) {
            const a = attachments[i];
            const chip = document.createElement('div');
            chip.className = 'dalfred-attach-chip' + (a.expired ? ' expired' : '');
            let icon;
            if (a.kind === 'image' && !a.expired && a.url && a.url !== '#') {
                icon = '<img src="' + a.url + '" class="dalfred-attach-thumb" alt="">';
            } else if (a.kind === 'image') {
                icon = (window.DalfredIcon ? DalfredIcon.render('triangle-alert', { class: 'dalfred-attach-icon' }) : '🖼️');
            } else {
                icon = (window.DalfredIcon ? DalfredIcon.render('file-text', { class: 'dalfred-attach-icon' }) : '📄');
            }
            const nameSafe = String(a.name).replace(/[<>&"]/g, function (c) {
                return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[c];
            });
            const size = a.size > 1024 * 1024
                ? (a.size / (1024 * 1024)).toFixed(1) + ' Mo'
                : (a.size / 1024).toFixed(1) + ' Ko';
            let nameNode;
            if (a.expired) {
                nameNode = '<span class="dalfred-attach-name" title="' + nameSafe + '">' + nameSafe + ' (expiré)</span>';
            } else if (!a.url || a.url === '#') {
                nameNode = '<span class="dalfred-attach-name" title="' + nameSafe + '">' + nameSafe + '</span>';
            } else {
                nameNode = '<a href="' + a.url + '" class="dalfred-attach-name" target="_blank" rel="noopener" title="' + nameSafe + '">' + nameSafe + '</a>';
            }
            chip.innerHTML = icon + nameNode + '<span class="dalfred-attach-size">' + size + '</span>';
            box.appendChild(chip);
        }
        contentWrapper.appendChild(box);
    }

    contentWrapper.appendChild(timeDiv);

    messageDiv.appendChild(avatar);
    messageDiv.appendChild(contentWrapper);

    messagesContainer.appendChild(messageDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

function formatMessage(content) {
    // Use shared markdown renderer (dalfred-markdown.js)
    if (typeof DalfredMarkdown !== 'undefined') {
        return DalfredMarkdown.formatMessage(content);
    }
    // Fallback: basic escaping if markdown module not loaded
    const div = document.createElement('div');
    div.textContent = content;
    return div.innerHTML.replace(/\n/g, '<br>');
}

function showTyping() {
    isTyping = true;
    document.getElementById('typing-indicator').classList.add('active');
    document.getElementById('fullscreen-send').disabled = true;

    const messagesContainer = document.getElementById('fullscreen-messages');
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

function hideTyping() {
    isTyping = false;
    document.getElementById('typing-indicator').classList.remove('active');
    document.getElementById('fullscreen-send').disabled = false;
}

function autoResizeTextarea() {
    const textarea = document.getElementById('fullscreen-input');
    textarea.style.height = 'auto';
    textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
}

function sendSuggestion(text) {
    document.getElementById('fullscreen-input').value = text;
    sendFullscreenMessage();
}

function saveFullscreenState(polling) {
    try {
        sessionStorage.setItem('dalfred_chat_state', JSON.stringify({
            threadId: fullscreenThreadId,
            isOpen: true,
            polling: polling,
            timestamp: Date.now()
        }));
    } catch (e) {
        console.warn('Could not save chat state:', e);
    }
}

function syncFloatingChat() {
    if (window.dalfredChat) {
        window.dalfredChat.threadId = fullscreenThreadId;
        window.dalfredChat.saveChatState();
    }
}

async function pollForFullscreenResponse(threadId) {
    const intervals = [1000, 2000, 3000, 5000]; // Exponential backoff
    const maxTime = 120000; // 2 min max
    const startTime = Date.now();
    let attempt = 0;

    while (Date.now() - startTime < maxTime) {
        const delay = intervals[Math.min(attempt, intervals.length - 1)];
        await new Promise(r => setTimeout(r, delay));

        try {
            const params = new URLSearchParams({
                action: 'poll',
                thread_id: threadId,
                token: 'notrequired'
            });

            const response = await fetch(`${DALFRED_BASE_URL}ajax/thread.php?${params}`, {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const result = await response.json();

            if (result.status === 'complete') {
                hideTyping();
                addFullscreenMessage('assistant', result.message);
                syncFloatingChat();
                saveFullscreenState(false);
                // Refresh the token usage badge after async response arrives.
                if (window.dalfredRefreshTokenBadge && threadId) {
                    window.dalfredRefreshTokenBadge(threadId);
                }
                return;
            }

            if (result.status === 'error') {
                hideTyping();
                addFullscreenMessage('assistant', '❌ ' + (result.error || 'Une erreur est survenue.'));
                saveFullscreenState(false);
                return;
            }

            // status === 'processing' — continue polling
        } catch (e) {
            console.warn('Dalfred: Poll error:', e);
        }
        attempt++;
    }

    // Timeout
    hideTyping();
    addFullscreenMessage('assistant', '❌ La réponse a pris trop de temps. Veuillez réessayer.');
    saveFullscreenState(false);
}

async function clearFullscreenChat() {
    if (confirm(DALFRED_I18N.confirmClear)) {
        // Clear messages
        const messagesContainer = document.getElementById('fullscreen-messages');
        messagesContainer.innerHTML = '<div class="dalfred-welcome-screen" id="welcome-message">' +
            '<h2>👋 ' + DALFRED_I18N.welcomeTitle + '</h2>' +
            '<p>' + DALFRED_I18N.welcomeIntro + '</p>' +
            '<div class="dalfred-suggestions">' +
            '<div class="dalfred-suggestion" onclick="sendSuggestion(DALFRED_I18N.suggestionUnpaidInvoices)">💰 ' + DALFRED_I18N.suggestionLabelUnpaidInvoices + '</div>' +
            '<div class="dalfred-suggestion" onclick="sendSuggestion(DALFRED_I18N.suggestionMonthlyRevenue)">📊 ' + DALFRED_I18N.suggestionLabelMonthlyRevenue + '</div>' +
            '<div class="dalfred-suggestion" onclick="sendSuggestion(DALFRED_I18N.suggestionRecentClients)">👥 ' + DALFRED_I18N.suggestionLabelRecentClients + '</div>' +
            '<div class="dalfred-suggestion" onclick="sendSuggestion(DALFRED_I18N.suggestionCreateInvoice)">➕ ' + DALFRED_I18N.suggestionLabelCreateInvoice + '</div>' +
            '</div></div>';

        // Close thread
        if (fullscreenThreadId) {
            try {
                await fetch(`${DALFRED_BASE_URL}ajax/thread.php?action=close&thread_id=${fullscreenThreadId}&token=notrequired`, {
                    method: 'GET',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
            } catch (error) {
                console.warn('Could not close thread:', error);
            }
        }

        fullscreenThreadId = null;

        // Hide the token usage badge — no active thread anymore.
        if (window.dalfredRefreshTokenBadge) {
            window.dalfredRefreshTokenBadge(null);
        }

        // Clear sessionStorage
        try {
            sessionStorage.removeItem('dalfred_chat_state');
        } catch (e) {}

        // Sync with floating chat
        if (window.dalfredChat) {
            window.dalfredChat.threadId = null;
            window.dalfredChat.messageHistory = [];
            window.dalfredChat.clearChatState();
        }
    }
}
</script>

<?php
// Prefill the chat input if ?prefill= was provided
if ($prefill !== '' && $prefill !== null) {
    print '<script>'
        . 'document.addEventListener("DOMContentLoaded", function() {'
        . '  var input = document.getElementById("fullscreen-input");'
        . '  if (input) {'
        . '    input.value = ' . json_encode($prefill, JSON_UNESCAPED_UNICODE) . ';'
        . '    input.dispatchEvent(new Event("input", {bubbles: true}));'
        . '    input.focus();'
        . '  }'
        . '});'
        . '</script>';
}

// End content
print '</div>';

llxFooter();
$db->close();
?>
