/**
 * Dalfred slash-commands autocomplete.
 *
 * Hooks into the chat textarea (.dalfred-input) and shows a dropdown when
 * the input starts with "/". No framework — vanilla DOM + fetch.
 *
 * Wire format: a command is an entry in llx_dalfred_knowledge with a
 * non-null command_name. The /help command is handled server-side and not
 * listed here (no point — it'd be redundant when the user is already
 * looking at the autocomplete list).
 */
(function () {
    'use strict';

    // URL of the autocomplete endpoint. DALFRED_BASE_URL is injected by
    // actions_dalfred.class.php (hook on every page) and by chat.php — it
    // resolves to dol_buildpath('/dalfred/', 1) so it respects the actual
    // case and location of the custom dir (e.g. /Custom/ on case-sensitive
    // hosts, or /htdocs/custom/ on shared-root installs). Never hardcode
    // '/custom/dalfred/' here: would break those installs.
    const BASE = (typeof DALFRED_BASE_URL === 'string' && DALFRED_BASE_URL)
        ? DALFRED_BASE_URL.replace(/\/$/, '')
        : ((typeof DOL_URL_ROOT === 'string' ? DOL_URL_ROOT : '') + '/custom/dalfred');
    const ENDPOINT = BASE + '/ajax/commands_autocomplete.php';

    const FETCH_THROTTLE_MS = 150;

    class CommandAutocomplete {
        constructor(input) {
            this.input = input;
            this.dropdown = null;
            this.items = [];
            this.activeIndex = -1;
            this.lastFetchAt = 0;
            this.lastPrefix = null;

            this.input.addEventListener('input', () => this.onInput());
            this.input.addEventListener('keydown', (e) => this.onKeydown(e));
            this.input.addEventListener('blur', () => {
                // Delay so a click on a dropdown item still registers.
                setTimeout(() => this.close(), 150);
            });

            // Reposition while open if the page scrolls or resizes (the
            // dropdown lives on body with position:fixed, so it does not
            // follow the input automatically).
            const reposition = () => {
                if (this.dropdown && this.dropdown.style.display !== 'none') {
                    this.positionDropdown();
                }
            };
            window.addEventListener('scroll', reposition, true);
            window.addEventListener('resize', reposition);
        }

        onInput() {
            const v = this.input.value;
            // Match "^/<prefix-so-far>" — at the very start of the input,
            // followed by zero or more allowed name chars, AND no space yet
            // (a space ends the command-name typing phase).
            const m = v.match(/^\/([a-z0-9-]*)$/);
            if (!m) {
                this.close();
                return;
            }
            this.fetchAndRender(m[1]);
        }

        async fetchAndRender(prefix) {
            const now = Date.now();
            if (prefix === this.lastPrefix && (now - this.lastFetchAt) < FETCH_THROTTLE_MS) {
                return;
            }
            this.lastPrefix = prefix;
            this.lastFetchAt = now;

            try {
                const url = ENDPOINT + '?prefix=' + encodeURIComponent(prefix);
                const res = await fetch(url, { credentials: 'same-origin' });
                if (!res.ok) {
                    this.close();
                    return;
                }
                const data = await res.json();
                if (!data.success || !Array.isArray(data.commands)) {
                    this.close();
                    return;
                }
                this.items = data.commands;
                this.activeIndex = this.items.length > 0 ? 0 : -1;
                this.render();
            } catch (_e) {
                this.close();
            }
        }

        render() {
            this.ensureDropdown();
            if (this.items.length === 0) {
                this.dropdown.innerHTML = '<div class="dalfred-cmd-empty">Aucune commande</div>';
                this.positionDropdown();
                this.dropdown.style.display = 'block';
                return;
            }

            const html = this.items.map((c, i) => {
                const cls = 'dalfred-cmd-item' + (i === this.activeIndex ? ' active' : '');
                const badge = c.scope === 'shared'
                    ? '<span class="dalfred-cmd-badge">partagée</span>'
                    : '';
                // Escape title — content comes from DB, never trust it raw.
                const titleEsc = String(c.title)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                return '<div class="' + cls + '" data-index="' + i + '">'
                    + '<code>/' + c.name + '</code> '
                    + '<span class="dalfred-cmd-title">' + titleEsc + '</span>'
                    + badge
                    + '</div>';
            }).join('');
            this.dropdown.innerHTML = html;
            this.positionDropdown();
            this.dropdown.style.display = 'block';

            // Click handler — wire after render so freshly inserted nodes are bound.
            this.dropdown.querySelectorAll('.dalfred-cmd-item').forEach((node) => {
                node.addEventListener('mousedown', (e) => {
                    // mousedown (not click) fires before the textarea blur.
                    e.preventDefault();
                    const idx = parseInt(node.getAttribute('data-index'), 10);
                    this.select(idx);
                });
            });
        }

        ensureDropdown() {
            if (this.dropdown) return;
            this.dropdown = document.createElement('div');
            this.dropdown.className = 'dalfred-cmd-dropdown';
            this.dropdown.style.display = 'none';
            // Attach to <body>, not the input's parent. The floating chat widget
            // has overflow:hidden on .dalfred-chat (needed for rounded corners
            // and message scroll), which would clip a dropdown positioned via
            // bottom:100% inside the widget. By living on body with position:
            // fixed, the dropdown is free of any ancestor clipping.
            document.body.appendChild(this.dropdown);
        }

        positionDropdown() {
            if (!this.dropdown) return;
            const r = this.input.getBoundingClientRect();
            // Sit just above the input. Width matches the input.
            this.dropdown.style.left = r.left + 'px';
            this.dropdown.style.width = r.width + 'px';
            // Use top + translate so the dropdown's bottom aligns with the
            // input's top minus a small gap. Avoids reading dropdown height
            // before it is rendered.
            this.dropdown.style.top = r.top + 'px';
            this.dropdown.style.transform = 'translateY(-100%) translateY(-4px)';
        }

        onKeydown(e) {
            if (!this.dropdown || this.dropdown.style.display === 'none' || this.items.length === 0) {
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.activeIndex = (this.activeIndex + 1) % this.items.length;
                this.render();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.activeIndex = (this.activeIndex - 1 + this.items.length) % this.items.length;
                this.render();
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                if (this.activeIndex >= 0) {
                    e.preventDefault();
                    this.select(this.activeIndex);
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                this.close();
            }
        }

        select(idx) {
            const cmd = this.items[idx];
            if (!cmd) return;
            // Replace the typed prefix (everything from / to current end of input)
            // with the full command name + a trailing space, ready for args.
            this.input.value = '/' + cmd.name + ' ';
            this.close();
            this.input.focus();
            // Move cursor to the end.
            const len = this.input.value.length;
            this.input.setSelectionRange(len, len);
            // Trigger input event so any auto-resize logic picks it up.
            this.input.dispatchEvent(new Event('input', { bubbles: true }));
        }

        close() {
            if (this.dropdown) {
                this.dropdown.style.display = 'none';
                this.items = [];
                this.activeIndex = -1;
            }
        }
    }

    // Bind to every chat input on the page once it appears. Two selectors:
    //   .dalfred-input            — the floating widget (dalfred.js builds it lazily)
    //   .dalfred-fullscreen-input — the full-page chat (chat.php, server-rendered)
    // We observe DOM mutations because the widget is injected late.
    function bindAll() {
        document.querySelectorAll('.dalfred-input, .dalfred-fullscreen-input').forEach((el) => {
            if (el.__dalfredCmdBound) return;
            el.__dalfredCmdBound = true;
            new CommandAutocomplete(el);
        });
    }

    // Initial pass + observe DOM mutations for the lazily-injected widget.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindAll);
    } else {
        bindAll();
    }
    const mo = new MutationObserver(() => bindAll());
    mo.observe(document.body || document.documentElement, { childList: true, subtree: true });
})();
