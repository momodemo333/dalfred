/* Dalfred — activity log detail modal.
 * Listens on .dalfred-log-view-btn click, decodes data-payload (base64 JSON),
 * renders structured sections, closes on backdrop/close/Escape.
 *
 * Assumes jQuery is loaded by Dolibarr.
 */
(function ($) {
    'use strict';

    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function tryPrettyJson(raw) {
        if (!raw) return '';
        try {
            return JSON.stringify(JSON.parse(raw), null, 2);
        } catch (e) {
            return raw;
        }
    }

    function renderModal(payload) {
        var $body = $('#dalfred-log-modal .dalfred-log-modal-body');
        var html = '';

        // Section 1: meta
        html += '<section>';
        html += '<h4>Context</h4>';
        html += '<dl class="meta-grid">';
        html += '<dt>Date</dt><dd>' + escapeHtml(payload.date) + '</dd>';
        html += '<dt>User</dt><dd>' + escapeHtml(payload.user) + '</dd>';
        html += '<dt>Event</dt><dd>' + escapeHtml(payload.event_type) + '</dd>';
        if (payload.tool_name) {
            html += '<dt>Tool</dt><dd><code>' + escapeHtml(payload.tool_name) + '</code></dd>';
        }
        if (payload.thread_id) {
            html += '<dt>Thread ID</dt><dd><code>' + escapeHtml(payload.thread_id) + '</code></dd>';
        }
        if (payload.duration_ms !== null && payload.duration_ms !== undefined) {
            html += '<dt>Duration</dt><dd>' + escapeHtml(payload.duration_ms) + ' ms</dd>';
        }
        html += '</dl>';
        html += '</section>';

        // Section 2: run context (provider/model JSON)
        if (payload.context) {
            html += '<section>';
            html += '<h4>Run context</h4>';
            html += '<pre>' + escapeHtml(tryPrettyJson(payload.context)) + '</pre>';
            html += '</section>';
        }

        // Section 3: error block
        if (payload.error_message || payload.stack_trace) {
            html += '<section>';
            html += '<h4>Error</h4>';
            if (payload.exception_class) {
                html += '<div class="error-class">' + escapeHtml(payload.exception_class) + '</div>';
            }
            if (payload.exception_file) {
                html += '<div style="color:#666;font-family:monospace;font-size:12px;margin-bottom:6px;">'
                    + escapeHtml(payload.exception_file) + '</div>';
            }
            if (payload.error_message) {
                html += '<pre>' + escapeHtml(payload.error_message) + '</pre>';
            }
            if (payload.stack_trace) {
                html += '<h4 style="margin-top:12px;">Stack trace</h4>';
                html += '<pre>' + escapeHtml(payload.stack_trace) + '</pre>';
            }
            html += '</section>';
        }

        // Section 4: tool inputs
        if (payload.tool_inputs && payload.tool_inputs !== '{}' && payload.tool_inputs !== '[]') {
            html += '<section>';
            html += '<h4>Tool inputs</h4>';
            html += '<pre>' + escapeHtml(tryPrettyJson(payload.tool_inputs)) + '</pre>';
            html += '</section>';
        }

        // Section 5: tool result
        if (payload.tool_result) {
            html += '<section>';
            html += '<h4>Tool result</h4>';
            html += '<pre>' + escapeHtml(payload.tool_result) + '</pre>';
            html += '</section>';
        }

        $body.html(html);
        $('#dalfred-log-modal').show();
    }

    function close() {
        $('#dalfred-log-modal').hide();
        $('#dalfred-log-modal .dalfred-log-modal-body').empty();
    }

    $(function () {
        $(document).on('click', '.dalfred-log-view-btn', function (e) {
            e.preventDefault();
            var encoded = $(this).data('payload');
            if (!encoded) return;
            var json;
            try {
                json = JSON.parse(decodeURIComponent(escape(atob(encoded))));
            } catch (err) {
                alert('Failed to decode log payload: ' + err.message);
                return;
            }
            renderModal(json);
        });

        $(document).on('click', '.dalfred-log-modal-backdrop, .dalfred-log-modal-close', function (e) {
            e.preventDefault();
            close();
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $('#dalfred-log-modal').is(':visible')) {
                close();
            }
        });
    });
})(jQuery);
