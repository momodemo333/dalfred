/**
 * Dalfred Attachments - file selection, drag&drop, preview
 *
 * Exposes window.DalfredAttachments with:
 *   attachToInput(button, previewZone, errorsZone, options) — wires the click
 *   enableDragDrop(zone, callbacks) — drag/drop on a chat zone
 *   addFiles(files, accept) — used by drop callbacks to push into the selection model
 *   getSelectedFiles() — current selection (for sendMessage)
 *   clear() — reset after send
 */
(function () {
    'use strict';

    var MAX_FILE_SIZE = 10 * 1024 * 1024;
    var MAX_FILES = 5;
    var selectedFiles = [];
    var changeListeners = [];

    function fmtSize(bytes) {
        if (bytes < 1024) return bytes + ' o';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' Ko';
        return (bytes / (1024 * 1024)).toFixed(1) + ' Mo';
    }

    function fileExtMatchesAccept(file, acceptStr) {
        if (!acceptStr) return true;
        var name = (file.name || '').toLowerCase();
        var allowed = acceptStr.split(',').map(function (s) { return s.trim().toLowerCase(); });
        for (var i = 0; i < allowed.length; i++) {
            if (allowed[i] && name.endsWith(allowed[i])) return true;
        }
        return false;
    }

    function notifyChange() {
        for (var i = 0; i < changeListeners.length; i++) {
            try { changeListeners[i](selectedFiles.slice()); } catch (e) {}
        }
    }

    function addFiles(files, acceptStr) {
        var added = 0;
        var errors = [];
        for (var i = 0; i < files.length; i++) {
            if (selectedFiles.length >= MAX_FILES) {
                errors.push('Maximum ' + MAX_FILES + ' fichiers par message');
                break;
            }
            var f = files[i];
            if (!fileExtMatchesAccept(f, acceptStr)) {
                errors.push(f.name + ' : type non supporté');
                continue;
            }
            if (f.size > MAX_FILE_SIZE) {
                errors.push(f.name + ' : trop volumineux (max ' + fmtSize(MAX_FILE_SIZE) + ')');
                continue;
            }
            selectedFiles.push(f);
            added++;
        }
        notifyChange();
        return { added: added, errors: errors };
    }

    function renderPreview(zoneEl, errorsEl) {
        if (!zoneEl) return;
        zoneEl.innerHTML = '';
        if (selectedFiles.length === 0) {
            zoneEl.style.display = 'none';
            if (errorsEl) errorsEl.innerHTML = '';
            return;
        }
        zoneEl.style.display = 'flex';
        for (var i = 0; i < selectedFiles.length; i++) {
            var f = selectedFiles[i];
            var chip = document.createElement('div');
            chip.className = 'dalfred-attach-chip';
            var iconHtml;
            if (f.type && f.type.indexOf('image/') === 0) {
                var img = document.createElement('img');
                img.className = 'dalfred-attach-thumb';
                img.src = URL.createObjectURL(f);
                img.onload = function () { URL.revokeObjectURL(this.src); };
                iconHtml = img.outerHTML;
            } else {
                iconHtml = window.DalfredIcon
                    ? DalfredIcon.render('file-text', { class: 'dalfred-attach-icon' })
                    : '&#128206;';
            }
            var safeName = (f.name || '').replace(/[<>&"']/g, function (c) {
                return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&#39;' }[c];
            });
            var nameDisplay = safeName.length > 25 ? safeName.substring(0, 22) + '&hellip;' : safeName;
            chip.innerHTML = iconHtml
                + '<span class="dalfred-attach-name" title="' + safeName + '">' + nameDisplay + '</span>'
                + '<span class="dalfred-attach-size">' + fmtSize(f.size) + '</span>'
                + '<button type="button" class="dalfred-attach-remove" data-index="' + i + '" aria-label="Retirer">&times;</button>';
            zoneEl.appendChild(chip);
        }
        // Wire remove buttons.
        var removes = zoneEl.querySelectorAll('.dalfred-attach-remove');
        for (var k = 0; k < removes.length; k++) {
            removes[k].addEventListener('click', function (ev) {
                var idx = parseInt(ev.currentTarget.getAttribute('data-index'), 10);
                if (!isNaN(idx)) {
                    selectedFiles.splice(idx, 1);
                    notifyChange();
                    renderPreview(zoneEl, errorsEl);
                }
            });
        }
    }

    function renderErrors(errorsEl, errors) {
        if (!errorsEl) return;
        if (!errors || errors.length === 0) {
            errorsEl.innerHTML = '';
            errorsEl.style.display = 'none';
            return;
        }
        errorsEl.style.display = 'block';
        errorsEl.innerHTML = errors.map(function (e) {
            var safe = e.replace(/[<>&"']/g, function (c) {
                return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&#39;' }[c];
            });
            return '<div class="dalfred-attach-error">' + safe + '</div>';
        }).join('');
    }

    window.DalfredAttachments = {
        attachToInput: function (button, previewZone, errorsZone, options) {
            options = options || {};
            var accept = options.accept || '.txt,.md,.log,.csv,.jpg,.jpeg,.png,.gif,.webp';
            // Hidden file input.
            var input = document.createElement('input');
            input.type = 'file';
            input.multiple = true;
            input.accept = accept;
            input.style.display = 'none';
            (button.parentNode || document.body).appendChild(input);

            button.addEventListener('click', function (ev) {
                ev.preventDefault();
                input.value = '';      // allow re-selecting the same file.
                input.click();
            });
            input.addEventListener('change', function () {
                var res = addFiles(input.files, accept);
                renderErrors(errorsZone, res.errors);
                renderPreview(previewZone, errorsZone);
            });

            changeListeners.push(function () { renderPreview(previewZone, errorsZone); });
        },

        enableDragDrop: function (zone, callbacks) {
            callbacks = callbacks || {};
            var depth = 0;
            zone.addEventListener('dragenter', function (ev) {
                if (!ev.dataTransfer || !ev.dataTransfer.types) return;
                if (Array.prototype.indexOf.call(ev.dataTransfer.types, 'Files') === -1) return;
                ev.preventDefault();
                depth++;
                zone.classList.add('dalfred-drop-active');
            });
            zone.addEventListener('dragover', function (ev) {
                if (zone.classList.contains('dalfred-drop-active')) ev.preventDefault();
            });
            zone.addEventListener('dragleave', function () {
                depth = Math.max(0, depth - 1);
                if (depth === 0) zone.classList.remove('dalfred-drop-active');
            });
            zone.addEventListener('drop', function (ev) {
                if (!ev.dataTransfer) return;
                ev.preventDefault();
                depth = 0;
                zone.classList.remove('dalfred-drop-active');
                if (callbacks.onDrop) {
                    callbacks.onDrop(ev.dataTransfer.files);
                }
            });
        },

        addFiles: function (files, accept) {
            return addFiles(files, accept || '.txt,.md,.log,.csv,.jpg,.jpeg,.png,.gif,.webp');
        },

        getSelectedFiles: function () {
            return selectedFiles.slice();
        },

        clear: function () {
            selectedFiles = [];
            notifyChange();
        }
    };
})();
