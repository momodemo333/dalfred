/**
 * Dalfred Markdown Renderer
 * Shared message formatting using marked.js + DOMPurify
 *
 * This module provides a single formatMessage() function used by both
 * the floating widget (dalfred.js) and the fullscreen chat (chat.php).
 *
 * Dependencies (must be loaded before this file):
 *   - js/lib/marked.min.js
 *   - js/lib/purify.min.js
 */

(function() {
    'use strict';

    // Configure marked options
    if (typeof marked !== 'undefined') {
        marked.setOptions({
            breaks: true,       // Convert \n to <br>
            gfm: true,          // GitHub Flavored Markdown (tables, strikethrough, etc.)
            headerIds: false,    // Don't generate IDs for headers (cleaner output)
            mangle: false        // Don't escape autolinked email addresses
        });
    }

    // DOMPurify configuration: whitelist safe HTML tags and attributes
    var PURIFY_CONFIG = {
        ALLOWED_TAGS: [
            // Text formatting
            'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del',
            // Headings
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            // Lists
            'ul', 'ol', 'li',
            // Links
            'a',
            // Code
            'code', 'pre',
            // Tables
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
            // Block elements
            'blockquote', 'hr', 'div', 'span',
            // Images (controlled)
            'img'
        ],
        ALLOWED_ATTR: [
            'href', 'target', 'rel', 'class',
            'src', 'alt', 'title', 'width', 'height',
            'colspan', 'rowspan', 'align',
            'data-label'
        ],
        // Force all links to open in new tab
        ADD_ATTR: ['target'],
        // Allow http/https links only
        ALLOWED_URI_REGEXP: /^(?:(?:https?|mailto):|[^a-z]|[a-z+.\-]+(?:[^a-z+.\-:]|$))/i
    };

    /**
     * Coerce a message content payload into a plain string.
     *
     * NeuronAI v3 serializes content as an array of ContentBlocks:
     *   [{type: 'text', content: '...'}, {type: 'image', ...}]
     * We concatenate all text blocks and ignore media blocks (which the chat UI
     * can't render today). Strings and null/undefined are passed through.
     */
    function normalizeContent(content) {
        if (content == null) return '';
        if (typeof content === 'string') return content;
        if (Array.isArray(content)) {
            return content
                .map(function (block) {
                    if (block == null) return '';
                    if (typeof block === 'string') return block;
                    if (block.type === 'text' && typeof block.content === 'string') {
                        return block.content;
                    }
                    return '';
                })
                .filter(function (s) { return s !== ''; })
                .join('\n');
        }
        // Object with a content field (defensive)
        if (typeof content === 'object' && typeof content.content === 'string') {
            return content.content;
        }
        return '';
    }

    /**
     * Format a message content string for display in the chat.
     * Handles both Markdown and raw HTML from AI responses.
     *
     * @param {string|Array|Object} content - Raw message content from the API
     * @returns {string} Safe HTML ready for innerHTML insertion
     */
    function formatMessage(content) {
        if (!content) return '';

        // NeuronAI v3 returns content as an array of ContentBlock objects
        // ([{type: 'text', content: '...'}, ...]). Legacy v2 format was a plain string.
        // Normalize to a string so the rest of the pipeline keeps working.
        content = normalizeContent(content);
        if (!content) return '';

        // If marked is not loaded, fall back to basic formatting
        if (typeof marked === 'undefined') {
            return formatMessageBasic(content);
        }

        var html;
        try {
            // Pre-process: convert Dolibarr internal paths to markdown links
            // e.g. /societe/card.php?socid=5 -> [/societe/card.php?socid=5](/societe/card.php?socid=5)
            var processed = preprocessContent(content);

            // Parse markdown to HTML
            html = marked.parse(processed);
        } catch (e) {
            console.warn('Dalfred: Markdown parsing failed, using basic formatter', e);
            return formatMessageBasic(content);
        }

        // Sanitize with DOMPurify
        if (typeof DOMPurify !== 'undefined') {
            html = DOMPurify.sanitize(html, PURIFY_CONFIG);
        }

        // Post-process: add classes and attributes to links
        html = postprocessHtml(html);

        return html;
    }

    /**
     * Escape characters that would break out of an HTML attribute value.
     * Used by the :::copy parser to safely embed user-provided labels.
     */
    function escapeAttr(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /**
     * Pre-process content before markdown parsing.
     * Converts standalone Dolibarr URLs/paths that aren't already in markdown syntax.
     *
     * @param {string} content - Raw content
     * @returns {string} Pre-processed content
     */
    function preprocessContent(content) {
        // :::copy [label]\n...\n:::  →  <div class="dalfred-copyable" data-label="...">...</div>
        // The label is optional. Blank lines around the body are CRITICAL: they
        // tell marked.js to re-enter markdown parsing inside the HTML block
        // (CommonMark spec, type 6 HTML blocks).
        content = content.replace(
            /^[ \t]*:::copy(?:[ \t]+([^\n]*))?\r?\n([\s\S]*?)\r?\n[ \t]*:::[ \t]*$/gm,
            function(m, label, body) {
                var labelAttr = label ? ' data-label="' + escapeAttr(label.trim()) + '"' : '';
                return '<div class="dalfred-copyable"' + labelAttr + '>\n\n' + body + '\n\n</div>';
            }
        );

        // Convert standalone internal paths (not already in markdown link syntax)
        // Match /path/to/file.php?params that aren't preceded by ]( or href="
        content = content.replace(
            /(?<!\]\()(?<!\bhref=["'])(?:^|\s)(\/[a-zA-Z0-9/_\-\.]+\.php(?:\?[^\s<)\]]*)?)/gm,
            function(match, path, offset) {
                // Check if this path is already inside a markdown link or HTML tag
                var before = content.substring(Math.max(0, offset - 10), offset);
                if (before.match(/\]\($/) || before.match(/href=["']$/)) {
                    return match;
                }
                var prefix = match.charAt(0) === '/' ? '' : match.charAt(0);
                return prefix + '[' + path.trim() + '](' + path.trim() + ')';
            }
        );

        return content;
    }

    /**
     * Post-process HTML after markdown parsing and sanitization.
     * Adds dalfred-specific classes and link attributes.
     *
     * @param {string} html - Sanitized HTML
     * @returns {string} Post-processed HTML
     */
    function postprocessHtml(html) {
        // Create a temporary DOM element for manipulation
        var temp = document.createElement('div');
        temp.innerHTML = html;

        // Process all links
        var links = temp.querySelectorAll('a');
        for (var i = 0; i < links.length; i++) {
            var link = links[i];
            link.classList.add('dalfred-link');
            link.setAttribute('target', '_blank');
            link.setAttribute('rel', 'noopener noreferrer');
        }

        // Process tables
        var tables = temp.querySelectorAll('table');
        for (var j = 0; j < tables.length; j++) {
            tables[j].classList.add('dalfred-table');
        }

        // Process code blocks
        var codeBlocks = temp.querySelectorAll('pre > code');
        for (var k = 0; k < codeBlocks.length; k++) {
            codeBlocks[k].parentElement.classList.add('dalfred-code-block');
        }

        // Process inline code
        var inlineCodes = temp.querySelectorAll('code');
        for (var l = 0; l < inlineCodes.length; l++) {
            if (!inlineCodes[l].parentElement.classList.contains('dalfred-code-block')) {
                inlineCodes[l].classList.add('dalfred-code-inline');
            }
        }

        // Inject header (optional label + copy button) into each :::copy block.
        // The header is added AFTER DOMPurify sanitization so it's not stripped.
        var copyables = temp.querySelectorAll('.dalfred-copyable');
        for (var m = 0; m < copyables.length; m++) {
            var block = copyables[m];
            // Idempotency: skip if a header is already present (e.g. re-rendering)
            if (block.querySelector(':scope > .dalfred-copyable-header')) continue;
            var label = block.getAttribute('data-label');

            var header = document.createElement('div');
            header.className = 'dalfred-copyable-header';

            if (label) {
                var labelEl = document.createElement('span');
                labelEl.className = 'dalfred-copyable-label';
                labelEl.textContent = label;
                header.appendChild(labelEl);
            }

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'dalfred-copyable-btn';
            btn.setAttribute('aria-label', 'Copier');
            btn.setAttribute('title', 'Copier');
            btn.innerHTML = '<span class="dalfred-copyable-icon">' + (window.DalfredIcon ? DalfredIcon.render('copy') : '&#128203;') + '</span>';
            header.appendChild(btn);

            block.insertBefore(header, block.firstChild);
        }

        // Inject copy button into fenced code blocks (bonus from spec).
        // Re-uses the same .dalfred-copyable-btn class so a single delegated
        // listener in dalfred-copyable.js handles both cases.
        var codeBlocksForCopy = temp.querySelectorAll('.dalfred-code-block');
        for (var n = 0; n < codeBlocksForCopy.length; n++) {
            var pre = codeBlocksForCopy[n];
            // Skip code blocks nested inside a :::copy block (the outer block already has its own button)
            if (pre.closest('.dalfred-copyable')) continue;
            // Idempotency: skip if a copy button is already present
            if (pre.querySelector(':scope > .dalfred-copyable-btn--code')) continue;
            var codeBtn = document.createElement('button');
            codeBtn.type = 'button';
            codeBtn.className = 'dalfred-copyable-btn dalfred-copyable-btn--code';
            codeBtn.setAttribute('aria-label', 'Copier');
            codeBtn.setAttribute('title', 'Copier');
            codeBtn.innerHTML = '<span class="dalfred-copyable-icon">' + (window.DalfredIcon ? DalfredIcon.render('copy') : '&#128203;') + '</span>';
            pre.appendChild(codeBtn);
        }

        return temp.innerHTML;
    }

    /**
     * Basic fallback formatter (used when marked.js is not available).
     * Replicates the original regex-based formatting.
     *
     * @param {string} content - Raw content
     * @returns {string} Formatted HTML
     */
    function formatMessageBasic(content) {
        var div = document.createElement('div');
        div.textContent = content;
        var formatted = div.innerHTML;

        // URLs
        formatted = formatted.replace(
            /(https?:\/\/[^\s<]+)/g,
            '<a href="$1" target="_blank" rel="noopener noreferrer" class="dalfred-link">$1</a>'
        );

        // Internal paths
        formatted = formatted.replace(
            /(\s|^)(\/[a-zA-Z0-9/_\-\.]+\.php(?:\?[^\s<]*)?)/g,
            '$1<a href="$2" class="dalfred-link">$2</a>'
        );

        // Markdown links
        formatted = formatted.replace(
            /\[([^\]]+)\]\(([^)]+)\)/g,
            '<a href="$2" target="_blank" rel="noopener noreferrer" class="dalfred-link">$1</a>'
        );

        // Basic markdown
        formatted = formatted
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.*?)\*/g, '<em>$1</em>')
            .replace(/`(.*?)`/g, '<code class="dalfred-code-inline">$1</code>')
            .replace(/\n/g, '<br>');

        return formatted;
    }

    // Expose globally for both widget and fullscreen chat
    window.DalfredMarkdown = {
        formatMessage: formatMessage,
        normalizeContent: normalizeContent
    };

})();
