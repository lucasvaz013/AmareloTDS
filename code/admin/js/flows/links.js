// ── Destinations panel (per-landing {link:N} → external URL) ──
// Clones the MVT panel pattern: state travels on the folder row's data-links attribute, a pure
// renderer paints the panel in both the PHP and template paths, a guard-first click handler
// processes its events, and collectLinks() is called from collectStepData. N is explicit and
// stable (stored on the row as data-n), so removing a destination never renumbers the others —
// the {link:N} the operator wrote in the landing HTML always points at the same slot.

function createLinkRow(n, url) {
    var row = document.createElement('div');
    row.className = 'flow-links-row';
    row.dataset.n = String(n);

    var badge = document.createElement('span');
    badge.className = 'flow-links-badge';
    badge.textContent = '{link:' + n + '}';

    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'form-control flow-links-url';
    input.value = String(url || '');
    input.placeholder = 'https://checkout.example/order?sub1={clickid}';

    // Optional "pick from the global library" dropdown. Choosing a registered destination copies
    // its effective URL into the input (a snapshot) and resets itself — the step still stores just
    // the URL string, so there is no live reference to the library.
    var pick = document.createElement('select');
    pick.className = 'form-control flow-links-pick';
    var library = Array.isArray(window.REGISTERED_DESTINATIONS) ? window.REGISTERED_DESTINATIONS : [];
    var placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = '— library —';
    pick.appendChild(placeholder);
    library.forEach(function (dest) {
        var option = document.createElement('option');
        option.value = dest.url;
        option.textContent = dest.name;
        pick.appendChild(option);
    });
    if (!library.length) {
        pick.style.display = 'none';
    }
    pick.addEventListener('change', function () {
        if (pick.value) input.value = pick.value;
        pick.selectedIndex = 0;
    });

    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-danger campaign-icon-btn flow-links-remove';
    remove.title = 'Remove destination';
    remove.innerHTML = '<i class="bi bi-trash"></i>';

    row.append(badge, input, pick, remove);
    return row;
}

// Next slot number = max existing + 1, or 1 for an empty list. The empty-list guard matters:
// Math.max() with no args is -Infinity, which would serialize to a null/0 slot the server later
// drops silently.
function nextN(rowsContainer) {
    var ns = Array.prototype.map.call(
        rowsContainer.querySelectorAll('.flow-links-row'),
        function (row) { return parseInt(row.dataset.n, 10); }
    ).filter(function (n) { return Number.isFinite(n) && n >= 1; });
    return ns.length ? Math.max.apply(null, ns) + 1 : 1;
}

export function renderLinksPanel(panel, links) {
    links = Array.isArray(links) ? links : [];
    panel.innerHTML = '';

    var header = document.createElement('div');
    header.className = 'flow-links-header';
    header.innerHTML = '<strong>Destinations</strong>'
        + '<small>Put the badge (e.g. <code>{link:1}</code>) in the landing HTML href</small>';

    var rows = document.createElement('div');
    rows.className = 'flow-links-rows';
    links.forEach(function (link) {
        rows.appendChild(createLinkRow(link.n, link.url));
    });

    var add = document.createElement('button');
    add.type = 'button';
    add.className = 'btn btn-outline-info btn-sm flow-links-add';
    add.textContent = '+ Destination';

    panel.append(header, rows, add);
}

export function collectLinks(folderItem) {
    var out = [];
    folderItem.querySelectorAll('.flow-links-row').forEach(function (row) {
        var n = parseInt(row.dataset.n, 10);
        var input = row.querySelector('.flow-links-url');
        var url = input ? input.value.trim() : '';
        if (Number.isFinite(n) && n >= 1 && url !== '') {
            out.push({ n: n, url: url });
        }
    });
    return out;
}

export function initializeLinksPanels() {
    document.querySelectorAll('.flow-links-panel').forEach(function (panel) {
        var item = panel.closest('.flow-path-item');
        var data = [];
        try {
            data = JSON.parse((item && item.dataset.links) || '[]');
        } catch (e) {}
        renderLinksPanel(panel, Array.isArray(data) ? data : []);
    });
}

export function handleLinksClick(e) {
    var panel = e.target.closest('.flow-links-panel');
    if (!panel) return false;

    var add = e.target.closest('.flow-links-add');
    if (add) {
        var rows = panel.querySelector('.flow-links-rows');
        rows.appendChild(createLinkRow(nextN(rows), ''));
        return true;
    }

    var remove = e.target.closest('.flow-links-remove');
    if (remove) {
        var row = remove.closest('.flow-links-row');
        if (row) row.remove();
        return true;
    }

    return false;
}
