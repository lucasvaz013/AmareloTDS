// ── Destinations page ──
// List editor for affiliate links. Each row previews its effective URL live (base + the selected
// network's params), composed exactly like Destination::compose on the server. Save posts the
// whole list; the server assigns ids and the page reloads.
(function () {
    'use strict';

    var configNode = document.getElementById('destinationsConfig');
    if (!configNode) return;

    var config;
    try { config = JSON.parse(configNode.textContent || '{}'); }
    catch (e) { return; }

    var endpoint = config.endpoint || 'destinationseditor.php';
    var paramsByNetwork = {};
    var nameByNetwork = {};
    (config.networks || []).forEach(function (n) {
        paramsByNetwork[n.id] = n.params || '';
        nameByNetwork[n.id] = n.name || n.id;
    });

    var rows = document.getElementById('destinationsRows');
    var alertBox = document.getElementById('destinationsAlert');
    var addBtn = document.getElementById('destinationsAdd');
    var saveBtn = document.getElementById('destinationsSave');

    function showAlert(message, isError) {
        alertBox.textContent = message || '';
        alertBox.classList.toggle('is-error', isError === true);
        alertBox.hidden = !message;
    }

    // Mirror of Destination::normalizeBaseUrl / ::compose (code/destinations.php).
    function normalizeBaseUrl(url) {
        url = (url || '').trim();
        if (url === '') return '';
        return /^https?:\/\//i.test(url) ? url : 'https://' + url;
    }

    function stripLeadingJoiner(params) {
        return (params || '').trim().replace(/^[?&]+/, '');
    }

    function compose(base, params) {
        base = (base || '').trim();
        params = stripLeadingJoiner(params);
        if (base === '' || params === '') return base;
        if (base.indexOf('?') !== -1) {
            var last = base.charAt(base.length - 1);
            return (last === '?' || last === '&') ? base + params : base + '&' + params;
        }
        return base + '?' + params;
    }

    function updatePreview(row) {
        var base = normalizeBaseUrl(row.querySelector('.destination-base').value);
        var networkId = row.querySelector('.destination-network').value;
        var params = paramsByNetwork.hasOwnProperty(networkId) ? paramsByNetwork[networkId] : '';
        var dangling = networkId !== '' && !paramsByNetwork.hasOwnProperty(networkId);
        var preview = row.querySelector('.destination-preview');
        var networkName = row.querySelector('.destination-network-name');
        var label = row.querySelector('.destination-preview-label');
        var url = row.querySelector('.destination-preview-url');
        url.textContent = compose(base, params);
        preview.classList.toggle('is-dangling', dangling);
        networkName.textContent = dangling ? 'Missing Network' : (nameByNetwork[networkId] || 'No Network');
        label.textContent = dangling ? 'network missing — base only:' : 'effective:';
    }

    function addRow() {
        var frag = document.getElementById('tpl-destination-row').content.cloneNode(true);
        var row = frag.querySelector('.destination-row');
        rows.appendChild(frag);
        updatePreview(row);
    }

    rows.addEventListener('click', function (e) {
        var remove = e.target.closest('.destination-remove');
        if (remove) {
            var row = remove.closest('.destination-row');
            if (row) row.remove();
        }
    });

    rows.addEventListener('input', function (e) {
        if (e.target.classList.contains('destination-base')) {
            updatePreview(e.target.closest('.destination-row'));
        }
    });

    rows.addEventListener('change', function (e) {
        if (e.target.classList.contains('destination-network')) {
            updatePreview(e.target.closest('.destination-row'));
        }
    });

    function collect() {
        var out = [];
        rows.querySelectorAll('.destination-row').forEach(function (row) {
            var name = row.querySelector('.destination-name').value.trim();
            var base = row.querySelector('.destination-base').value.trim();
            if (name === '' || base === '') return;
            out.push({
                id: row.dataset.id || '',
                name: name,
                base_url: base,
                network_id: row.querySelector('.destination-network').value || ''
            });
        });
        return out;
    }

    function save() {
        saveBtn.disabled = true;
        fetch(endpoint + '?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ destinations: collect() })
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
