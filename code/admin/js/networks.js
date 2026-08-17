// ── Networks page ──
// A flat list editor: rows render server-side from common.settings, JS handles add/remove/save.
// Save posts the whole list; the server assigns ids and the page reloads to pick them up.
(function () {
    'use strict';

    var configNode = document.getElementById('networksConfig');
    if (!configNode) return;

    var config;
    try { config = JSON.parse(configNode.textContent || '{}'); }
    catch (e) { return; }

    var endpoint = config.endpoint || 'networkseditor.php';
    var rows = document.getElementById('networksRows');
    var alertBox = document.getElementById('networksAlert');
    var addBtn = document.getElementById('networksAdd');
    var saveBtn = document.getElementById('networksSave');

    function showAlert(message, isError) {
        alertBox.textContent = message || '';
        alertBox.classList.toggle('is-error', isError === true);
        alertBox.hidden = !message;
    }

    function addRow() {
        var frag = document.getElementById('tpl-network-row').content.cloneNode(true);
        rows.appendChild(frag);
    }

    rows.addEventListener('click', function (e) {
        var remove = e.target.closest('.network-remove');
        if (remove) {
            var row = remove.closest('.network-row');
            if (row) row.remove();
        }
    });

    function collect() {
        var out = [];
        rows.querySelectorAll('.network-row').forEach(function (row) {
            var name = row.querySelector('.network-name').value.trim();
            var params = row.querySelector('.network-params').value.trim();
            if (name === '') return;
            out.push({ id: row.dataset.id || '', name: name, params: params });
        });
        return out;
    }

    function save() {
        saveBtn.disabled = true;
        fetch(endpoint + '?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ networks: collect() })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    showAlert(data.result, true);
                } else {
                    showAlert('Saved.', false);
                    setTimeout(function () { location.reload(); }, 400);
                }
            })
            .catch(function (err) { showAlert('Save failed: ' + err, true); })
            .finally(function () { saveBtn.disabled = false; });
    }

    if (addBtn) addBtn.addEventListener('click', addRow);
    if (saveBtn) saveBtn.addEventListener('click', save);
})();
