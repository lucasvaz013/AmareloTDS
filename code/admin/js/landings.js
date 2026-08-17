// ── Landings page ──
// Manages the caching/landings folders directly. Upload reuses zipupload.php and editing reuses
// the file editor (window.openFileEditor) — the same endpoints a Flow step uses — so a landing
// registered here is immediately pickable inside any step's "Add Existing".
(function () {
    'use strict';

    var configNode = document.getElementById('landingsConfig');
    if (!configNode) return;

    var config;
    try { config = JSON.parse(configNode.textContent || '{}'); }
    catch (e) { return; }

    var endpoint = config.endpoint || 'landingseditor.php';
    var uploadUrl = config.upload || 'zipupload.php';
    var landingFolder = config.landingFolder || 'caching/landings';
    var NAME_RE = /^[a-zA-Z0-9_\-\.]+$/;

    var body = document.getElementById('landingsBody');
    var countNode = document.getElementById('landingsCount');
    var alertBox = document.getElementById('landingsAlert');
    var uploadBtn = document.getElementById('landingsUpload');
    var refreshBtn = document.getElementById('landingsRefresh');

    function showAlert(message, isError) {
        if (!alertBox) return;
        alertBox.textContent = message || '';
        alertBox.classList.toggle('is-error', isError === true);
        alertBox.hidden = !message;
    }

    function escapeHtml(value) {
        var d = document.createElement('div');
        d.textContent = value == null ? '' : String(value);
        return d.innerHTML;
    }

    function formatBytes(bytes) {
        if (!Number.isFinite(bytes) || bytes < 0) return '—';
        if (bytes === 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        var value = bytes / Math.pow(1024, i);
        return new Intl.NumberFormat(undefined, { maximumFractionDigits: i === 0 ? 0 : 1 }).format(value) + ' ' + units[i];
    }

    function formatDate(mtime) {
        if (!mtime) return '—';
        try { return new Date(mtime * 1000).toLocaleString(); } catch (e) { return '—'; }
    }

    function previewUrl(name) {
        return '../' + landingFolder + '/' + name.split('/').map(encodeURIComponent).join('/') + '/';
    }

    function api(action, opts) {
        opts = opts || {};
        var url = endpoint + '?action=' + encodeURIComponent(action);
        if (opts.params) {
            Object.keys(opts.params).forEach(function (k) {
                url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(opts.params[k]);
            });
        }
        var init = { method: opts.method || 'GET' };
        if (opts.body) init.body = opts.body;
        return fetch(url, init).then(function (r) {
            return r.json().catch(function () { return { error: true, result: 'Bad response (' + r.status + ')' }; });
        }).then(function (data) {
            if (data.error) throw new Error(data.result || 'Request failed');
            return data;
        });
    }

    function actionButton(icon, title, cls, onClick) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-sm ' + cls;
        b.title = title;
        b.setAttribute('aria-label', title);
        b.innerHTML = '<i class="bi ' + icon + '"></i>';
        b.addEventListener('click', onClick);
        return b;
    }

    function render(landings) {
        body.innerHTML = '';
        if (!landings.length) {
            body.innerHTML = '<tr><td colspan="5" class="landings-empty">No landings yet. Upload a ZIP to get started.</td></tr>';
            countNode.textContent = '';
            return;
        }
        countNode.textContent = landings.length + (landings.length === 1 ? ' landing' : ' landings');
        landings.forEach(function (landing) {
            var tr = document.createElement('tr');
            var warn = landing.hasIndex ? '' :
                ' <span class="landings-warn" title="No index.php/index.html at the root — this landing 404s at runtime"><i class="bi bi-exclamation-triangle-fill"></i></span>';
            tr.innerHTML =
                '<td class="landings-name"><i class="bi bi-folder-fill"></i> ' + escapeHtml(landing.name) + warn + '</td>' +
                '<td>' + escapeHtml(landing.files) + '</td>' +
                '<td>' + escapeHtml(formatBytes(landing.bytes)) + '</td>' +
                '<td>' + escapeHtml(formatDate(landing.mtime)) + '</td>' +
                '<td class="landings-actions"></td>';
            var actions = tr.querySelector('.landings-actions');
            actions.appendChild(actionButton('bi-eye', 'Preview', 'btn-outline-info', function () {
                window.open(previewUrl(landing.name), '_blank', 'noopener');
            }));
            actions.appendChild(actionButton('bi-pencil-square', 'Edit files', 'btn-outline-light', function () {
                if (typeof window.openFileEditor === 'function') {
                    window.openFileEditor(landing.name, 'landing');
                } else {
                    showAlert('File editor is not loaded.', true);
                }
            }));
            actions.appendChild(actionButton('bi-files', 'Duplicate', 'btn-outline-secondary', function () {
                duplicate(landing.name);
            }));
            actions.appendChild(actionButton('bi-trash', 'Delete', 'btn-outline-danger', function () {
                remove(landing.name);
            }));
            body.appendChild(tr);
        });
    }

    function load() {
        showAlert('');
        return api('list').then(function (data) {
            render(data.landings || []);
        }).catch(function (e) {
            body.innerHTML = '<tr><td colspan="5" class="landings-empty">Error: ' + escapeHtml(e.message) + '</td></tr>';
        });
    }

    function upload() {
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = '.zip';
        input.style.display = 'none';
        document.body.appendChild(input);
        input.addEventListener('change', function () {
            if (!input.files.length) { input.remove(); return; }
            var file = input.files[0];
            var name = prompt('Folder name for this landing:');
            if (!name || !name.trim()) { input.remove(); return; }
            name = name.trim();
            if (!NAME_RE.test(name)) {
                showAlert('Invalid folder name. Use only letters, numbers, hyphens, underscores, dots.', true);
                input.remove();
                return;
            }
            var fd = new FormData();
            fd.append('zipfile', file);
            fd.append('folder', name);
            fd.append('type', 'landing');
            uploadBtn.disabled = true;
            uploadBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Uploading…';
            fetch(uploadUrl, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) showAlert('Upload error: ' + data.result, true);
                    else { showAlert('Uploaded "' + data.folder + '".', false); load(); }
                })
                .catch(function (err) { showAlert('Upload failed: ' + err, true); })
                .finally(function () {
                    uploadBtn.disabled = false;
                    uploadBtn.innerHTML = '<i class="bi bi-upload"></i> Upload ZIP';
                    input.remove();
                });
        });
        input.click();
    }

    function duplicate(name) {
        var target = prompt('Duplicate "' + name + '" as:', name + '-copy');
        if (!target || !target.trim()) return;
        target = target.trim();
        if (!NAME_RE.test(target)) { showAlert('Invalid folder name.', true); return; }
        var fd = new FormData();
        fd.append('folder', name);
        fd.append('newName', target);
        api('duplicate', { method: 'POST', body: fd }).then(function () {
            showAlert('Duplicated to "' + target + '".', false);
            load();
        }).catch(function (e) { showAlert(e.message, true); });
    }

    // Deleting warns rather than blocks: the scan lists which campaigns still reference the
    // landing so the operator makes an informed call, then the delete proceeds if confirmed.
    function remove(name) {
        var message = 'Delete landing "' + name + '"? This removes the folder and all its files from disk.';
        api('usage', { params: { folder: name } }).then(function (data) {
            var usages = data.usages || [];
            if (usages.length) {
                var lines = usages.map(function (u) {
                    return '• ' + u.campaign + ' (' + u.where.join(', ') + ')';
                }).join('\n');
                message = 'Landing "' + name + '" is still used by ' + usages.length +
                    (usages.length === 1 ? ' campaign' : ' campaigns') + ':\n\n' + lines +
                    '\n\nDeleting it breaks those references (they 404 at runtime). Delete anyway?';
            }
            return message;
        }).catch(function (e) {
            // If the usage scan fails, fall back to a plain confirm rather than blocking deletion.
            return 'Could not check usage (' + e.message + '). Delete "' + name + '" anyway?';
        }).then(function (finalMessage) {
            if (!window.confirm(finalMessage)) return;
            var fd = new FormData();
            fd.append('folder', name);
            api('delete', { method: 'POST', body: fd }).then(function () {
                showAlert('Deleted "' + name + '".', false);
                load();
            }).catch(function (e) { showAlert(e.message, true); });
        });
    }

    if (uploadBtn) uploadBtn.addEventListener('click', upload);
    if (refreshBtn) refreshBtn.addEventListener('click', load);
    load();
})();
