/**
 * Dalfred Copyable Blocks — Runtime
 *
 * Handles click events on .dalfred-copyable-btn elements:
 * extracts content from the parent block, writes both HTML and plain text
 * to the clipboard via the modern Clipboard API (with two fallbacks),
 * and shows visual feedback on the button.
 *
 * Triggered by markup produced by dalfred-markdown.js postprocessHtml():
 *   - <div class="dalfred-copyable" data-label="..."> (prose)
 *   - <pre class="dalfred-code-block"> (fenced code blocks)
 *
 * Single delegated listener attached to document. Survives DOM re-renders.
 */
(function() {
    'use strict';

    /**
     * Extract HTML and plain-text payloads from a copyable block.
     * For .dalfred-copyable: clones the block, removes the header, returns
     *   the inner HTML and a plain-text conversion of the content.
     * For .dalfred-code-block: returns only plain text (the code itself).
     */
    function extractCopyContent(blockEl, isCodeBlock) {
        if (isCodeBlock) {
            var code = blockEl.querySelector('code');
            var text = code ? code.textContent : blockEl.textContent;
            // For code, plain text is authoritative; HTML is the same text
            // wrapped in a <pre><code> so a paste into a rich editor preserves
            // monospace formatting if the editor supports it.
            var safeText = text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
            return {
                html: '<pre><code>' + safeText + '</code></pre>',
                plain: text
            };
        }

        var clone = blockEl.cloneNode(true);
        var header = clone.querySelector('.dalfred-copyable-header');
        if (header) header.remove();

        return {
            html: clone.innerHTML.trim(),
            plain: htmlToPlainText(clone).replace(/^\n+|\n+$/g, '')
        };
    }

    /**
     * Convert a DOM subtree to clean plain text following the rules from the spec:
     *   - <strong>/<b>/<em>/<i>/<u>/<code> inline → textContent (markers stripped)
     *   - <a href> → anchor text only (URL dropped)
     *   - <ul>/<ol>/<li> → "- item" or "1. item" lines
     *   - <p> → text + double newline
     *   - <br> → single newline
     *   - <pre><code> → preserved verbatim
     *   - <table> → tab-separated rows (acceptable degradation)
     */
    function htmlToPlainText(node) {
        if (node.nodeType === 3) {
            // Text node
            return node.nodeValue;
        }
        if (node.nodeType !== 1) {
            return '';
        }

        var tag = node.tagName.toLowerCase();

        if (tag === 'br') {
            return '\n';
        }

        if (tag === 'pre') {
            // Preserve code block content verbatim
            return node.textContent + '\n\n';
        }

        if (tag === 'a') {
            // Drop the URL, keep the anchor text
            return walkChildren(node);
        }

        if (tag === 'ul' || tag === 'ol') {
            var lines = [];
            var idx = 1;
            var children = node.children;
            for (var i = 0; i < children.length; i++) {
                if (children[i].tagName && children[i].tagName.toLowerCase() === 'li') {
                    var prefix = (tag === 'ol') ? (idx + '. ') : '- ';
                    lines.push(prefix + walkChildren(children[i]).trim());
                    idx++;
                }
            }
            return lines.join('\n') + '\n\n';
        }

        if (tag === 'p') {
            return walkChildren(node).trim() + '\n\n';
        }

        if (tag === 'table') {
            var rows = [];
            var trs = node.querySelectorAll('tr');
            for (var t = 0; t < trs.length; t++) {
                var cells = trs[t].querySelectorAll('th, td');
                var cellTexts = [];
                for (var c = 0; c < cells.length; c++) {
                    cellTexts.push(walkChildren(cells[c]).trim());
                }
                rows.push(cellTexts.join('\t'));
            }
            return rows.join('\n') + '\n\n';
        }

        // Default: recurse into children, no extra formatting
        return walkChildren(node);
    }

    function walkChildren(node) {
        var out = '';
        var nodes = node.childNodes;
        for (var i = 0; i < nodes.length; i++) {
            out += htmlToPlainText(nodes[i]);
        }
        return out;
    }

    /**
     * Write to clipboard with three fallback levels.
     * Returns a Promise<boolean> indicating success.
     */
    function copyToClipboard(html, plain) {
        // Level 1: ClipboardItem (HTML + plain text simultaneously)
        if (navigator.clipboard && window.ClipboardItem) {
            try {
                var item = new ClipboardItem({
                    'text/html': new Blob([html], { type: 'text/html' }),
                    'text/plain': new Blob([plain], { type: 'text/plain' })
                });
                return navigator.clipboard.write([item])
                    .then(function() { return true; })
                    .catch(function(e) {
                        console.warn('Dalfred: ClipboardItem failed, falling back to writeText', e);
                        return copyToClipboardFallback(plain);
                    });
            } catch (e) {
                console.warn('Dalfred: ClipboardItem threw, falling back to writeText', e);
                return copyToClipboardFallback(plain);
            }
        }
        return copyToClipboardFallback(plain);
    }

    function copyToClipboardFallback(plain) {
        // Level 2: writeText (plain text only)
        if (navigator.clipboard) {
            return navigator.clipboard.writeText(plain)
                .then(function() { return true; })
                .catch(function(e) {
                    console.warn('Dalfred: writeText failed, falling back to execCommand', e);
                    return Promise.resolve(legacyCopyExec(plain));
                });
        }
        return Promise.resolve(legacyCopyExec(plain));
    }

    function legacyCopyExec(plain) {
        // Level 3: execCommand('copy') — works on HTTP and old browsers
        try {
            var ta = document.createElement('textarea');
            ta.value = plain;
            ta.setAttribute('readonly', '');
            ta.setAttribute('aria-hidden', 'true');
            ta.setAttribute('tabindex', '-1');
            ta.style.position = 'absolute';
            ta.style.top = '0';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            var ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return ok;
        } catch (e) {
            console.warn('Dalfred: legacy copy failed', e);
            return false;
        }
    }

    /**
     * Render an icon by name, falling back to a unicode character if the
     * SVG icon registry isn't loaded (rare — would only happen if dalfred-icons.js
     * failed to load before this script).
     */
    function renderIcon(name, fallback) {
        if (window.DalfredIcon) return DalfredIcon.render(name);
        return fallback;
    }

    function showCopiedFeedback(btn) {
        btn.classList.add('is-copied');
        var icon = btn.querySelector('.dalfred-copyable-icon');
        if (icon) icon.innerHTML = renderIcon('check', '✓');
        btn.setAttribute('aria-label', 'Copié');
        setTimeout(function() {
            btn.classList.remove('is-copied');
            if (icon) icon.innerHTML = renderIcon('copy', '&#128203;');
            btn.setAttribute('aria-label', 'Copier');
        }, 2000);
    }

    function showFailedFeedback(btn) {
        btn.classList.add('is-failed');
        var icon = btn.querySelector('.dalfred-copyable-icon');
        if (icon) icon.innerHTML = renderIcon('x', '✕');
        btn.setAttribute('aria-label', 'Échec de la copie');
        setTimeout(function() {
            btn.classList.remove('is-failed');
            if (icon) icon.innerHTML = renderIcon('copy', '&#128203;');
            btn.setAttribute('aria-label', 'Copier');
        }, 2000);
    }

    // Single delegated click listener
    document.addEventListener('click', function(e) {
        var btn = e.target.closest && e.target.closest('.dalfred-copyable-btn');
        if (!btn) return;

        e.preventDefault();

        var block = btn.closest('.dalfred-copyable') || btn.closest('.dalfred-code-block');
        if (!block) return;

        var isCodeBlock = block.classList.contains('dalfred-code-block');
        var content = extractCopyContent(block, isCodeBlock);

        copyToClipboard(content.html, content.plain).then(function(ok) {
            if (ok) showCopiedFeedback(btn);
            else showFailedFeedback(btn);
        });
    }, false);

    // Expose for tests
    window.DalfredCopyable = {
        extractCopyContent: extractCopyContent,
        htmlToPlainText: htmlToPlainText
    };

})();
