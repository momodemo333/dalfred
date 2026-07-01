/**
 * Dalfred JavaScript
 * Modern chat interface with AI assistant
 */

// Prevent multiple loading
if (typeof window.dalfredChat !== 'undefined') {
    console.log('Dalfred: Already loaded, skipping');
} else {

class DalfredChat {
    constructor() {
        this.isOpen = false;
        this.isFullscreen = false;
        this.isTyping = false;
        this.threadId = null;
        this.messageHistory = [];
        this.persistenceKey = 'dalfred_chat_state';
        this.isRestoring = false;

        this.init();
    }

    init() {
        this.createChatElements();
        this.bindEvents();
        this.restoreChatState();
        // Also fetch active thread from server to ensure persistence
        this.fetchActiveThread();
    }

    createChatElements() {
        const cfg = window.DalfredConfig || {};
        const agentName = cfg.agentName || 'Dalfred';
        const agentIconHtml = cfg.agentIconHtml || '🤖';

        // Chat toggle button
        const toggle = document.createElement('button');
        toggle.className = 'dalfred-toggle';
        toggle.innerHTML = '<span style="font-size: 28px;">' + agentIconHtml + '</span>';
        toggle.title = (typeof DALFRED_TRANSLATIONS !== 'undefined' && DALFRED_TRANSLATIONS.widgetTitle) ? DALFRED_TRANSLATIONS.widgetTitle : agentName + ' - Assistant IA';
        toggle.id = 'dalfred-toggle';

        // Chat container
        const chat = document.createElement('div');
        chat.className = 'dalfred-chat';
        chat.id = 'dalfred-chat';
        chat.innerHTML = this.getChatHTML();

        // Add to page
        document.body.appendChild(toggle);
        document.body.appendChild(chat);

        // Store references
        this.toggle = toggle;
        this.chat = chat;
        this.messages = chat.querySelector('.dalfred-messages');
        this.input = chat.querySelector('.dalfred-input');
        this.sendBtn = chat.querySelector('.dalfred-send');

        this.enableToggleDragging();
    }

    enableToggleDragging() {
        const btn = this.toggle;
        const DRAG_THRESHOLD = 5;
        const STORAGE_KEY = 'dalfred_toggle_position';
        const self = this;
        this._wasDragged = false;
        let isDragging = false;
        let startX = 0, startY = 0, offsetX = 0, offsetY = 0;

        function getPoint(e) {
            return (e.touches && e.touches.length) ? e.touches[0] : e;
        }

        function applyPosition(left, top) {
            const rect = btn.getBoundingClientRect();
            const maxLeft = window.innerWidth - rect.width;
            const maxTop = window.innerHeight - rect.height;
            left = Math.max(0, Math.min(maxLeft, left));
            top = Math.max(0, Math.min(maxTop, top));
            btn.style.right = 'auto';
            btn.style.bottom = 'auto';
            btn.style.left = left + 'px';
            btn.style.top = top + 'px';
        }

        function savePosition() {
            try {
                const rect = btn.getBoundingClientRect();
                localStorage.setItem(STORAGE_KEY, JSON.stringify({ left: rect.left, top: rect.top }));
            } catch (err) {}
        }

        function restorePosition() {
            try {
                const raw = localStorage.getItem(STORAGE_KEY);
                if (!raw) return;
                const pos = JSON.parse(raw);
                if (typeof pos.left !== 'number' || typeof pos.top !== 'number') return;
                applyPosition(pos.left, pos.top);
            } catch (err) {}
        }

        function onMove(e) {
            const p = getPoint(e);
            const dx = p.clientX - startX;
            const dy = p.clientY - startY;
            if (!isDragging && (dx * dx + dy * dy) >= DRAG_THRESHOLD * DRAG_THRESHOLD) {
                isDragging = true;
                btn.classList.add('dalfred-dragging');
            }
            if (isDragging) {
                if (e.cancelable) e.preventDefault();
                applyPosition(p.clientX - offsetX, p.clientY - offsetY);
            }
        }

        function onUp() {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend', onUp);
            document.removeEventListener('touchcancel', onUp);
            if (isDragging) {
                btn.classList.remove('dalfred-dragging');
                savePosition();
                self._wasDragged = true;
            }
            isDragging = false;
        }

        function onDown(e) {
            if (e.type === 'mousedown') {
                if (e.button !== 0) return;
                e.preventDefault();
            }
            const p = getPoint(e);
            const rect = btn.getBoundingClientRect();
            startX = p.clientX;
            startY = p.clientY;
            offsetX = p.clientX - rect.left;
            offsetY = p.clientY - rect.top;
            isDragging = false;
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            document.addEventListener('touchmove', onMove, { passive: false });
            document.addEventListener('touchend', onUp);
            document.addEventListener('touchcancel', onUp);
        }

        btn.setAttribute('draggable', 'false');
        btn.addEventListener('dragstart', function (e) { e.preventDefault(); });
        btn.addEventListener('mousedown', onDown);
        btn.addEventListener('touchstart', onDown, { passive: true });

        window.addEventListener('resize', function () {
            if (btn.style.left && btn.style.left !== 'auto') {
                const rect = btn.getBoundingClientRect();
                applyPosition(rect.left, rect.top);
            }
        });

        restorePosition();
    }

    /**
     * Position the chat panel relative to the (possibly dragged) toggle button.
     *
     * Anchors the panel next to the toggle on the side that has more space —
     * horizontally first (left of toggle if toggle is in the right half, else
     * right of toggle), then vertically (above if toggle is in the bottom
     * half, else below). Falls back to clamping the panel inside the viewport
     * with an 8px margin so it stays visible even when the toggle is dragged
     * close to a screen edge or when the viewport is smaller than the panel.
     *
     * In fullscreen mode the panel is laid out by CSS — we skip positioning
     * and clear any inline coordinates we set previously.
     */
    positionChatPanel() {
        if (this.isFullscreen) {
            this.chat.style.left = '';
            this.chat.style.top = '';
            this.chat.style.right = '';
            this.chat.style.bottom = '';
            return;
        }

        const MARGIN = 8;
        const GAP = 10;
        const toggleRect = this.toggle.getBoundingClientRect();
        const vw = window.innerWidth;
        const vh = window.innerHeight;

        // Measure the panel by temporarily showing it offscreen — when called
        // from openChat() it is not yet visible, so getBoundingClientRect()
        // would return zeros.
        const wasActive = this.chat.classList.contains('active');
        const prevVisibility = this.chat.style.visibility;
        const prevDisplay = this.chat.style.display;
        if (!wasActive) {
            this.chat.style.visibility = 'hidden';
            this.chat.style.display = 'flex';
        }
        const panelWidth = this.chat.offsetWidth;
        const panelHeight = this.chat.offsetHeight;
        if (!wasActive) {
            this.chat.style.display = prevDisplay;
            this.chat.style.visibility = prevVisibility;
        }

        // Horizontal anchor: prefer the side with more space.
        const spaceLeft = toggleRect.left;
        const spaceRight = vw - toggleRect.right;
        let left;
        if (spaceRight >= panelWidth + GAP) {
            left = toggleRect.right + GAP;
        } else if (spaceLeft >= panelWidth + GAP) {
            left = toggleRect.left - panelWidth - GAP;
        } else {
            // Not enough space on either side — align with the toggle then clamp.
            left = (spaceLeft >= spaceRight) ? toggleRect.left - panelWidth - GAP : toggleRect.right + GAP;
        }

        // Vertical anchor: prefer to keep the panel aligned with the toggle
        // top, but flip above if the panel would overflow the bottom edge.
        let top = toggleRect.top;
        if (top + panelHeight + MARGIN > vh) {
            top = toggleRect.bottom - panelHeight;
        }

        // Clamp inside viewport (handles tiny screens and edge cases).
        left = Math.max(MARGIN, Math.min(vw - panelWidth - MARGIN, left));
        top = Math.max(MARGIN, Math.min(vh - panelHeight - MARGIN, top));

        this.chat.style.right = 'auto';
        this.chat.style.bottom = 'auto';
        this.chat.style.left = left + 'px';
        this.chat.style.top = top + 'px';
    }

    getChatHTML() {
        const t = (typeof DALFRED_TRANSLATIONS !== 'undefined') ? DALFRED_TRANSLATIONS : {};
        const cfg = window.DalfredConfig || {};
        const agentName = cfg.agentName || 'Dalfred';
        const agentIconHtml = cfg.agentIconHtml || '🤖';
        return `
            <div class="dalfred-header">
                <div>
                    <h3><span>${agentIconHtml}</span> ${agentName}</h3>
                    <div class="dalfred-status">
                        <span class="dalfred-status-dot"></span>
                        <span>${t.online || 'En ligne'}</span>
                    </div>
                </div>
                <div class="dalfred-header-actions">
                    <button class="dalfred-knowledge" title="${t.memory || 'Mémoire'}">${DalfredIcon.render('brain')}</button>
                    <button class="dalfred-expand" title="${t.fullscreen || 'Ouvrir en plein écran'}">${DalfredIcon.render('expand')}</button>
                    <button class="dalfred-clear" title="${t.newConversation || 'Nouvelle conversation'}">${DalfredIcon.render('trash-2')}</button>
                    <button class="dalfred-close" title="${t.close || 'Fermer'}">×</button>
                </div>
            </div>

            <div class="dalfred-messages">
                <!-- Messages will be added here -->
            </div>

            <div class="dalfred-input-container">
                <!-- Same layout as the fullscreen page: errors + preview chips
                     live inside the input area, above the row of controls. -->
                <div class="dalfred-attach-errors" style="display:none;"></div>
                <div class="dalfred-attach-preview" style="display:none;"></div>

                <div class="dalfred-input-row">
                    ${(window.DalfredConfig && window.DalfredConfig.attachmentsEnabled)
                        ? '<button type="button" class="dalfred-attach-btn" title="' + ((typeof DALFRED_TRANSLATIONS !== 'undefined' && DALFRED_TRANSLATIONS.attachButton) || 'Joindre un fichier') + '">' + (window.DalfredIcon ? DalfredIcon.render('paperclip') : '📎') + '</button>'
                        : ''}
                    <textarea
                        class="dalfred-input"
                        placeholder="${t.placeholder || 'Posez votre question...'}"
                        rows="1"
                    ></textarea>
                    <button class="dalfred-send" title="${t.send || 'Envoyer'}">
                        ${DalfredIcon.render('send')}
                    </button>
                </div>
            </div>
        `;
    }

    bindEvents() {
        // Toggle chat
        let toggleTimeout;
        this.toggle.addEventListener('click', (e) => {
            e.stopPropagation();
            // If the user just dragged the button, swallow the synthetic click
            // that follows mouseup so the chat does not open/close on drop.
            if (this._wasDragged) {
                this._wasDragged = false;
                return;
            }
            if (toggleTimeout) return;

            toggleTimeout = setTimeout(() => {
                toggleTimeout = null;
            }, 300);

            this.toggleChat();
        });

        // Close chat
        this.chat.querySelector('.dalfred-close').addEventListener('click', (e) => {
            e.stopPropagation();
            this.closeChat();
        });

        // Toggle fullscreen
        this.chat.querySelector('.dalfred-expand').addEventListener('click', () => {
            if (this.isFullscreen) {
                this.collapseFromFullscreen();
            } else {
                this.expandToFullscreen();
            }
        });

        // Knowledge/Memory
        this.chat.querySelector('.dalfred-knowledge').addEventListener('click', () => {
            window.open(window.location.origin + DALFRED_BASE_URL + 'knowledge.php', '_blank');
        });

        // Clear history
        this.chat.querySelector('.dalfred-clear').addEventListener('click', () => this.clearHistory());

        // Send message
        this.sendBtn.addEventListener('click', () => this.sendMessage());

        // Input events
        this.input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.sendMessage();
            }
        });

        this.input.addEventListener('input', () => this.autoResize());

        // Escape key: exit fullscreen first, then close chat
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (this.isFullscreen) {
                    this.collapseFromFullscreen();
                } else if (this.isOpen) {
                    this.closeChat();
                }
            }
        });

        // Save state before page unload
        window.addEventListener('beforeunload', () => {
            this.saveChatState();
        });

        // Save state on visibility change
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.saveChatState();
            }
        });

        // Wire the attachments module if enabled.
        if (window.DalfredConfig && window.DalfredConfig.attachmentsEnabled && window.DalfredAttachments) {
            var attachBtn = this.chat.querySelector('.dalfred-attach-btn');
            var previewZone = this.chat.querySelector('.dalfred-attach-preview');
            var errorsZone = this.chat.querySelector('.dalfred-attach-errors');
            if (attachBtn && previewZone) {
                DalfredAttachments.attachToInput(attachBtn, previewZone, errorsZone, {
                    accept: window.DalfredConfig.attachmentsAcceptedTypes || '.txt,.md,.log,.csv,.jpg,.jpeg,.png,.gif,.webp'
                });
            }
            // Drag & drop on the whole chat panel.
            this.chat.setAttribute('data-drop-label', (typeof DALFRED_TRANSLATIONS !== 'undefined' && DALFRED_TRANSLATIONS.attachDropZone) || 'Déposez vos fichiers ici');
            var accept = window.DalfredConfig.attachmentsAcceptedTypes || '.txt,.md,.log,.csv,.jpg,.jpeg,.png,.gif,.webp';
            var _self = this;
            DalfredAttachments.enableDragDrop(this.chat, {
                onDrop: function (files) {
                    DalfredAttachments.addFiles(files, accept);
                }
            });
        }
    }

    toggleChat() {
        if (this.isOpen) {
            this.closeChat();
        } else {
            this.openChat();
        }
    }

    async openChat() {
        this.isOpen = true;
        this.positionChatPanel();
        this.chat.classList.add('active');
        this.toggle.classList.add('active');
        this.toggle.innerHTML = '×';

        this.saveChatState();

        // Focus input
        setTimeout(() => {
            this.input.focus();
        }, 300);

        // Always revalidate the active thread against the server before showing
        // history — another tab or device may have started a more recent
        // conversation since this tab was loaded. fetchActiveThread() will
        // reload history itself if it switches threads.
        const cachedThread = this.threadId;
        await this.fetchActiveThread();
        const threadChanged = cachedThread !== this.threadId;

        // Load chat history if we have a thread (and it wasn't just reloaded
        // by fetchActiveThread because it switched).
        if (this.threadId && !threadChanged && this.messages.children.length === 0) {
            await this.loadChatHistory();
        } else if (!this.threadId && this.messages.children.length === 0) {
            this.showWelcomeMessage();
        }
    }

    closeChat() {
        // Exit fullscreen first if active
        if (this.isFullscreen) {
            this.collapseFromFullscreen();
        }

        this.isOpen = false;
        this.chat.classList.remove('active');
        this.toggle.classList.remove('active');
        const _cfg = window.DalfredConfig || {};
        this.toggle.innerHTML = '<span style="font-size: 28px;">' + (_cfg.agentIconHtml || '🤖') + '</span>';

        this.saveChatState();
    }

    expandToFullscreen() {
        this.isFullscreen = true;
        // Clear inline coordinates set by positionChatPanel() so the
        // fullscreen CSS (top/left/right/bottom: 20px) takes effect.
        this.chat.style.left = '';
        this.chat.style.top = '';
        this.chat.style.right = '';
        this.chat.style.bottom = '';
        this.chat.classList.add('dalfred-fullscreen-mode');
        this.toggle.style.display = 'none';

        // Swap expand button icon and title
        const expandBtn = this.chat.querySelector('.dalfred-expand');
        expandBtn.innerHTML = DalfredIcon.render('minimize');
        const t = (typeof DALFRED_TRANSLATIONS !== 'undefined') ? DALFRED_TRANSLATIONS : {};
        expandBtn.title = t.reduce || 'Réduire';

        // Add overlay backdrop
        if (!document.getElementById('dalfred-overlay')) {
            const overlay = document.createElement('div');
            overlay.id = 'dalfred-overlay';
            overlay.className = 'dalfred-overlay';
            overlay.addEventListener('click', () => this.collapseFromFullscreen());
            document.body.appendChild(overlay);
        }

        // Prevent body scroll
        document.body.style.overflow = 'hidden';

        this.scrollToBottom();
        this.input.focus();
        this.saveChatState();
    }

    collapseFromFullscreen() {
        this.isFullscreen = false;
        this.chat.classList.remove('dalfred-fullscreen-mode');
        this.toggle.style.display = '';
        // Re-anchor the floating panel next to the toggle (which may have
        // been dragged before fullscreen was triggered).
        if (this.isOpen) {
            this.positionChatPanel();
        }

        // Restore expand button icon and title
        const expandBtn = this.chat.querySelector('.dalfred-expand');
        expandBtn.innerHTML = DalfredIcon.render('expand');
        const t = (typeof DALFRED_TRANSLATIONS !== 'undefined') ? DALFRED_TRANSLATIONS : {};
        expandBtn.title = t.fullscreen || 'Ouvrir en plein écran';

        // Remove overlay
        const overlay = document.getElementById('dalfred-overlay');
        if (overlay) overlay.remove();

        // Restore body scroll
        document.body.style.overflow = '';

        this.scrollToBottom();
        this.saveChatState();
    }

    async sendMessage() {
        const message = this.input.value.trim();
        if (!message || this.isTyping) return;

        // Build a local-preview payload so the user's bubble shows the
        // attached files immediately, mirroring the {kind,name,mime,size,url}
        // shape that ThreadService::getThreadMessages emits on history reload.
        let bubbleAttachments = [];
        if (window.DalfredAttachments) {
            const files = DalfredAttachments.getSelectedFiles();
            bubbleAttachments = files.map(function (f) {
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

        // Add user message to UI (with attachment chips if any)
        this.addMessage('user', message, null, bubbleAttachments);
        this.input.value = '';
        this.autoResize();

        // Show typing indicator
        this.showTyping();

        try {
            const context = this.getCurrentContext();

            // Branch: use multipart FormData when files are attached, GET otherwise.
            let response;
            const hasAttachments = window.DalfredAttachments && DalfredAttachments.getSelectedFiles().length > 0;
            if (hasAttachments) {
                const fd = new FormData();
                fd.append('token', 'notrequired');
                fd.append('message', message);
                if (this.threadId) fd.append('thread_id', this.threadId);
                fd.append('context', JSON.stringify(context));
                const files = DalfredAttachments.getSelectedFiles();
                for (let i = 0; i < files.length; i++) {
                    fd.append('attachments[]', files[i]);
                }
                const fetchResp = await fetch(`${DALFRED_BASE_URL}ajax/chat.php`, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (!fetchResp.ok) {
                    throw new Error(`HTTP ${fetchResp.status}: ${fetchResp.statusText}`);
                }
                response = await fetchResp.json();

                // Surface attachment errors returned by the server.
                if (response.attachment_errors && response.attachment_errors.length > 0) {
                    const errZone = this.chat.querySelector('.dalfred-attach-errors');
                    if (errZone) {
                        errZone.style.display = '';
                        errZone.innerHTML = response.attachment_errors.map(function (e) {
                            return '<div class="dalfred-attach-error">' + String(e).replace(/[<>&]/g, function (c) {
                                return { '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c];
                            }) + '</div>';
                        }).join('');
                    }
                }

                // Reset attachment selection on successful send.
                DalfredAttachments.clear();
                const prev = this.chat.querySelector('.dalfred-attach-preview');
                if (prev) { prev.style.display = 'none'; prev.innerHTML = ''; }
                const errZ = this.chat.querySelector('.dalfred-attach-errors');
                if (errZ && (!response.attachment_errors || response.attachment_errors.length === 0)) {
                    errZ.style.display = 'none'; errZ.innerHTML = '';
                }
            } else {
                response = await this.callAPI('chat', {
                    message: message,
                    thread_id: this.threadId,
                    context: JSON.stringify(context)
                });
            }

            if (response.status === 'processing') {
                // Async mode: server accepted the message, poll for response
                this.threadId = response.thread_id;
                this.saveChatState();
                this.pollForResponse(response.thread_id);
            } else if (response.status === 'already_processing') {
                this.hideTyping();
                this.addErrorMessage(response.error || 'Un message est déjà en cours de traitement.');
            } else if (response.success) {
                // Sync mode (fallback): immediate response
                this.hideTyping();
                this.threadId = response.thread_id;
                this.addMessage('assistant', response.message);
                this.saveChatState();
            } else {
                this.hideTyping();
                const t = (typeof DALFRED_TRANSLATIONS !== 'undefined') ? DALFRED_TRANSLATIONS : {};
                this.addErrorMessage(response.error || t.errorCommunication || 'Erreur de communication');
            }

        } catch (error) {
            this.hideTyping();
            const te = (typeof DALFRED_TRANSLATIONS !== 'undefined') ? DALFRED_TRANSLATIONS : {};
            this.addErrorMessage((te.errorConnection || 'Erreur de connexion') + ': ' + error.message);
        }
    }

    async pollForResponse(threadId) {
        const intervals = [1000, 2000, 3000, 5000]; // Exponential backoff
        const maxTime = 120000; // 2 min max
        const startTime = Date.now();
        let attempt = 0;

        while (Date.now() - startTime < maxTime) {
            const delay = intervals[Math.min(attempt, intervals.length - 1)];
            await new Promise(r => setTimeout(r, delay));

            try {
                const result = await this.callAPI('thread', {
                    action: 'poll',
                    thread_id: threadId
                });

                if (result.status === 'complete') {
                    this.hideTyping();
                    this.addMessage('assistant', result.message);
                    this.saveChatState();
                    return;
                }

                if (result.status === 'error') {
                    this.hideTyping();
                    this.addErrorMessage(result.error || 'Une erreur est survenue.');
                    this.saveChatState();
                    return;
                }

                // status === 'processing' — continue polling
            } catch (e) {
                console.warn('Dalfred: Poll error:', e);
            }
            attempt++;
        }

        // Timeout
        this.hideTyping();
        this.addErrorMessage('La réponse a pris trop de temps. Veuillez réessayer.');
        this.saveChatState();
    }

    addMessage(type, content, timestamp = null, attachments = []) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `dalfred-message ${type}`;

        const contentDiv = document.createElement('div');
        contentDiv.className = 'dalfred-message-content';
        contentDiv.innerHTML = this.formatMessage(content);

        const timeDiv = document.createElement('div');
        timeDiv.className = 'dalfred-message-time';
        timeDiv.textContent = timestamp || this.formatTime(new Date());

        messageDiv.appendChild(contentDiv);

        // Render attachment chips if any.
        if (attachments && attachments.length > 0) {
            const box = document.createElement('div');
            box.className = 'dalfred-attach-history';
            for (let i = 0; i < attachments.length; i++) {
                const a = attachments[i];
                const chip = document.createElement('div');
                chip.className = 'dalfred-attach-chip' + (a.expired ? ' expired' : '');
                let icon;
                if (a.kind === 'image' && !a.expired) {
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
            messageDiv.appendChild(box);
        }

        messageDiv.appendChild(timeDiv);

        this.messages.appendChild(messageDiv);
        this.scrollToBottom();

        this.messageHistory.push({
            type: type,
            content: content,
            timestamp: timestamp || new Date().toISOString()
        });
    }

    addErrorMessage(error) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'dalfred-message assistant';

        const contentDiv = document.createElement('div');
        contentDiv.className = 'dalfred-error';
        contentDiv.innerHTML = DalfredIcon.render('circle-alert', { class: 'dalfred-error-icon' }) + ' ' + error.replace(/[<>&"']/g, function(c){return {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;'}[c];});

        messageDiv.appendChild(contentDiv);
        this.messages.appendChild(messageDiv);
        this.scrollToBottom();
    }

    formatMessage(content) {
        // Use shared markdown renderer (dalfred-markdown.js)
        if (typeof DalfredMarkdown !== 'undefined') {
            return DalfredMarkdown.formatMessage(content);
        }
        // Fallback: basic escaping if markdown module not loaded
        const div = document.createElement('div');
        div.textContent = content;
        return div.innerHTML.replace(/\n/g, '<br>');
    }

    formatTime(date) {
        return date.toLocaleTimeString(undefined, {
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    scrollToBottom() {
        setTimeout(() => {
            this.messages.scrollTop = this.messages.scrollHeight;
        }, 100);
    }

    autoResize() {
        this.input.style.height = 'auto';
        this.input.style.height = Math.min(this.input.scrollHeight, 80) + 'px';
    }

    getCurrentContext() {
        const context = {
            current_page: window.location.pathname,
            page_title: document.title,
            chat_type: 'floating'
        };

        // Use page context provided by PHP if available
        if (typeof DALFRED_PAGE_CONTEXT !== 'undefined' && DALFRED_PAGE_CONTEXT) {
            context.url = DALFRED_PAGE_CONTEXT.url || window.location.pathname;
            context.script = DALFRED_PAGE_CONTEXT.script || '';
            context.referer = DALFRED_PAGE_CONTEXT.referer || '';
        }

        // Add URL parameters (excluding sensitive ones)
        const urlParams = new URLSearchParams(window.location.search);
        const params = {};
        for (let [key, value] of urlParams.entries()) {
            if (!['token', 'api_key', 'password'].includes(key.toLowerCase())) {
                params[key] = value;
            }
        }
        if (Object.keys(params).length > 0) {
            context.parameters = params;
        }

        // Try to extract object context
        if (urlParams.get('id')) {
            context.object_id = urlParams.get('id');
        }

        // Detect object type from URL
        if (window.location.pathname.includes('/societe/')) {
            context.object_type = 'societe';
        } else if (window.location.pathname.includes('/facture/')) {
            context.object_type = 'facture';
        } else if (window.location.pathname.includes('/commande/')) {
            context.object_type = 'commande';
        } else if (window.location.pathname.includes('/propal/')) {
            context.object_type = 'propal';
        }

        return context;
    }

    async callAPI(endpoint, data) {
        const params = new URLSearchParams();
        params.append('token', 'notrequired');

        for (let [key, value] of Object.entries(data)) {
            if (value !== null && value !== undefined) {
                params.append(key, value);
            }
        }

        const response = await fetch(`${DALFRED_BASE_URL}ajax/${endpoint}.php?${params}`, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        return await response.json();
    }

    async clearHistory() {
        const t = (typeof DALFRED_TRANSLATIONS !== 'undefined') ? DALFRED_TRANSLATIONS : {};
        if (confirm(t.clearConfirm || 'Effacer la conversation et en démarrer une nouvelle ?')) {
            this.messages.innerHTML = '';
            this.messageHistory = [];

            if (this.threadId) {
                try {
                    await this.callAPI('thread', {
                        action: 'close',
                        thread_id: this.threadId
                    });
                } catch (error) {
                    console.warn('Could not close thread:', error);
                }
            }

            this.threadId = null;
            this.clearChatState();
            this.showWelcomeMessage();
        }
    }

    showWelcomeMessage() {
        const t = (typeof DALFRED_TRANSLATIONS !== 'undefined') ? DALFRED_TRANSLATIONS : {};
        const cfg = window.DalfredConfig || {};
        const agentName = cfg.agentName || 'Dalfred';
        const welcomeHTML = `
            <div class="dalfred-welcome">
                <h4>👋 ${t.welcomeTitle || ('Bonjour ! Je suis ' + agentName + ', votre assistant Dolibarr')}</h4>
                <p>${t.welcomeIntro || 'Je peux vous aider avec :'}</p>
                <ul>
                    <li>🔍 ${t.helpSearch || 'Rechercher des clients, factures, commandes'}</li>
                    <li>📊 ${t.helpAnalyze || 'Analyser vos données et statistiques'}</li>
                    <li>➕ ${t.helpCreate || 'Créer de nouveaux éléments'}</li>
                    <li>❓ ${t.helpQuestions || 'Répondre à vos questions sur Dolibarr'}</li>
                </ul>
                <p><strong>${t.tryExample || 'Essayez : "Mes factures impayées" ou "CA du mois"'}</strong></p>
            </div>
        `;

        const messageDiv = document.createElement('div');
        messageDiv.className = 'dalfred-message assistant';
        messageDiv.innerHTML = welcomeHTML;

        this.messages.appendChild(messageDiv);
        this.scrollToBottom();
    }

    showTyping() {
        this.isTyping = true;

        const typingDiv = document.createElement('div');
        typingDiv.className = 'dalfred-typing active';
        typingDiv.id = 'dalfred-typing-indicator';
        typingDiv.innerHTML = `
            <div class="dalfred-typing-dot"></div>
            <div class="dalfred-typing-dot"></div>
            <div class="dalfred-typing-dot"></div>
        `;

        this.messages.appendChild(typingDiv);
        this.scrollToBottom();
    }

    hideTyping() {
        this.isTyping = false;

        const typingIndicator = document.getElementById('dalfred-typing-indicator');
        if (typingIndicator) {
            typingIndicator.remove();
        }
    }

    saveChatState() {
        try {
            const state = {
                isOpen: this.isOpen,
                threadId: this.threadId,
                polling: this.isTyping,  // Track if we're waiting for async response
                timestamp: Date.now()
            };

            sessionStorage.setItem(this.persistenceKey, JSON.stringify(state));
        } catch (error) {
            console.warn('Could not save chat state:', error);
        }
    }

    restoreChatState() {
        try {
            const stateData = sessionStorage.getItem(this.persistenceKey);
            if (!stateData) return;

            const state = JSON.parse(stateData);

            // Check if state is recent (within last 30 minutes)
            const now = Date.now();
            const stateAge = now - (state.timestamp || 0);
            const maxAge = 30 * 60 * 1000;

            if (stateAge > maxAge) {
                sessionStorage.removeItem(this.persistenceKey);
                return;
            }

            // Restore thread ID
            if (state.threadId) {
                this.threadId = state.threadId;
            }

            // Restore open state
            if (state.isOpen) {
                this.isRestoring = true;
                setTimeout(() => {
                    this.openChat();
                    this.isRestoring = false;
                }, 100);
            }

            // Resume polling if thread was processing when user navigated away
            if (state.threadId && state.polling) {
                this.isRestoring = true;
                setTimeout(() => {
                    this.showTyping();
                    this.pollForResponse(state.threadId);
                    this.isRestoring = false;
                }, 200);
            }

        } catch (error) {
            console.warn('Could not restore chat state:', error);
            sessionStorage.removeItem(this.persistenceKey);
        }
    }

    clearChatState() {
        try {
            sessionStorage.removeItem(this.persistenceKey);
        } catch (error) {
            console.warn('Could not clear chat state:', error);
        }
    }

    async loadChatHistory() {
        try {
            const response = await this.callAPI('thread', {
                action: 'history',
                thread_id: this.threadId
            });

            if (response.success && response.messages && response.messages.length > 0) {
                this.messages.innerHTML = '';
                this.messageHistory = [];

                response.messages.forEach(msg => {
                    this.addMessage(msg.role, msg.content, msg.formatted_time, msg.attachments || []);
                });
            } else {
                this.showWelcomeMessage();
            }

        } catch (error) {
            console.warn('Could not load chat history:', error);
            this.showWelcomeMessage();
        }
    }

    /**
     * Fetch the user's most-recent thread from the server.
     *
     * Always queries the server (even if a threadId is already cached in
     * sessionStorage), because another tab — or a brand-new conversation
     * started elsewhere — may have produced a more recent thread. The cached
     * value is treated as an optimistic hint, not the source of truth.
     *
     * If the server returns a different thread than the cached one, we adopt
     * the server's choice and clear any history rendered for the stale thread.
     */
    async fetchActiveThread() {
        try {
            const response = await this.callAPI('thread', {
                action: 'active'
            });

            if (!response.success || !response.thread) {
                return;
            }

            const serverThreadId = response.thread.thread_id;

            if (this.threadId && this.threadId !== serverThreadId) {
                console.log('Dalfred: cached thread is stale (' + this.threadId + ' vs server ' + serverThreadId + '), switching');
                // Drop the stale rendering — loadChatHistory() below will rebuild.
                if (this.messages) {
                    this.messages.innerHTML = '';
                }
            }

            const isNewThread = this.threadId !== serverThreadId;
            this.threadId = serverThreadId;
            this.saveChatState();

            // If we just switched threads while the chat is open, reload history.
            if (isNewThread && this.isOpen && this.messages) {
                await this.loadChatHistory();
            }
        } catch (error) {
            console.warn('Could not fetch active thread:', error);
        }
    }
}

// Initialize chat when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Check if Dalfred is enabled
    if (typeof DALFRED_ENABLED !== 'undefined' && DALFRED_ENABLED) {
        window.dalfredChat = new DalfredChat();
    }
});

} // End of protection block
