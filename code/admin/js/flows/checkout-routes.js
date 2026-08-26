// Checkout Routes are configured once per folder step. Every route has the same slot numbers;
// each route maps those slots to Destinations belonging to one Network.

function networks() {
    return Array.isArray(window.CHECKOUT_ROUTE_NETWORKS) ? window.CHECKOUT_ROUTE_NETWORKS : [];
}

function destinationsFor(networkId) {
    var all = Array.isArray(window.CHECKOUT_ROUTE_DESTINATIONS) ? window.CHECKOUT_ROUTE_DESTINATIONS : [];
    return all.filter(function (destination) { return destination.network_id === networkId; });
}

function option(value, label, selected) {
    var out = document.createElement('option');
    out.value = value;
    out.textContent = label;
    out.selected = value === selected;
    return out;
}

function createDestinationSelect(networkId, destinationId) {
    var select = document.createElement('select');
    select.className = 'form-select flow-checkout-route-destination';
    select.appendChild(option('', '— choose destination —', destinationId));
    destinationsFor(networkId).forEach(function (destination) {
        select.appendChild(option(destination.id, destination.name, destinationId));
    });
    return select;
}

function createSlotRow(n, networkId, destinationId) {
    var row = document.createElement('div');
    row.className = 'flow-checkout-slot-row';
    row.dataset.n = String(n);

    var badge = document.createElement('code');
    badge.className = 'flow-checkout-slot-badge';
    badge.textContent = '{link:' + n + '}';

    var select = createDestinationSelect(networkId, destinationId || '');
    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-outline-danger campaign-icon-btn flow-checkout-slot-remove';
    remove.title = 'Remove slot from every route';
    remove.innerHTML = '<i class="bi bi-trash"></i>';

    row.append(badge, select, remove);
    return row;
}

function createRoute(route, routeCount) {
    var card = document.createElement('div');
    card.className = 'flow-checkout-route';

    var header = document.createElement('div');
    header.className = 'flow-checkout-route-header';

    var network = document.createElement('select');
    network.className = 'form-select flow-checkout-route-network';
    network.appendChild(option('', '— choose Network —', route.network_id || ''));
    networks().forEach(function (entry) {
        network.appendChild(option(entry.id, entry.name, route.network_id || ''));
    });

    var weight = document.createElement('input');
    weight.type = 'number';
    weight.min = '0';
    weight.max = '100';
    weight.step = '1';
    weight.className = 'form-control flow-checkout-route-weight';
    weight.value = String(routeCount === 1 ? 100 : (route.weight || 0));
    weight.hidden = routeCount === 1;
    weight.title = 'Route weight (%)';

    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-danger campaign-icon-btn flow-checkout-route-remove';
    remove.title = 'Remove route';
    remove.innerHTML = '<i class="bi bi-trash"></i>';
    header.append(network, weight, remove);

    var slots = document.createElement('div');
    slots.className = 'flow-checkout-route-slots';
    (route.links || []).forEach(function (link) {
        slots.appendChild(createSlotRow(link.n, route.network_id || '', link.destination_id || ''));
    });

    card.append(header, slots);
    return card;
}

function readRoutes(panel) {
    var routes = [];
    panel.querySelectorAll('.flow-checkout-route').forEach(function (card) {
        var links = [];
        card.querySelectorAll('.flow-checkout-slot-row').forEach(function (row) {
            links.push({
                n: parseInt(row.dataset.n, 10),
                destination_id: row.querySelector('.flow-checkout-route-destination').value
            });
        });
        routes.push({
            network_id: card.querySelector('.flow-checkout-route-network').value,
            weight: parseInt(card.querySelector('.flow-checkout-route-weight').value, 10) || 0,
            links: links
        });
    });
    if (routes.length === 1) routes[0].weight = 100;
    return routes;
}

function redistributeWeights(panel) {
    var weights = panel.querySelectorAll('.flow-checkout-route-weight');
    var count = weights.length;
    if (!count) return;
    var base = Math.floor(100 / count);
    var remainder = 100 - (base * count);
    weights.forEach(function (input, index) {
        input.hidden = count === 1;
        input.value = String(base + (index < remainder ? 1 : 0));
    });
}

function updateLegacyLinksState(panel) {
    var step = panel.closest('.step-section');
    if (!step) return;
    var active = panel.querySelectorAll('.flow-checkout-route').length > 0;
    step.querySelectorAll('.flow-links-panel').forEach(function (legacy) {
        legacy.classList.toggle('flow-links-readonly', active);
        legacy.querySelectorAll('input, select, button').forEach(function (control) {
            control.disabled = active;
        });
        var message = legacy.querySelector('.flow-links-source-message');
        if (active && !message) {
            message = document.createElement('small');
            message.className = 'flow-links-source-message';
            message.textContent = 'Checkout Routes are the source of truth for {link:N} on this step.';
            legacy.prepend(message);
        } else if (!active && message) {
            message.remove();
        }
    });
}

export function renderCheckoutRoutesPanel(panel, routes) {
    routes = Array.isArray(routes) ? routes : [];
    panel.innerHTML = '';

    var heading = document.createElement('div');
    heading.className = 'flow-checkout-routes-heading';
    heading.innerHTML = '<strong>Checkout Routes</strong><small>Split the same {link:N} slots across checkout Networks. URLs freeze on the first pageview.</small>';

    var cards = document.createElement('div');
    cards.className = 'flow-checkout-routes-list';
    routes.forEach(function (route) { cards.appendChild(createRoute(route, routes.length)); });

    var actions = document.createElement('div');
    actions.className = 'flow-checkout-routes-actions';
    actions.innerHTML = '<button type="button" class="btn btn-outline-info btn-sm flow-checkout-route-add">+ Route</button>'
        + '<button type="button" class="btn btn-outline-secondary btn-sm flow-checkout-slot-add">+ Slot</button>';

    panel.append(heading, cards, actions);
    updateLegacyLinksState(panel);
}

export function collectCheckoutRoutes(stepSec) {
    var action = stepSec.querySelector('.flow-step-action:checked');
    if (!action || action.value !== 'folder') return [];
    var panel = stepSec.querySelector('.flow-checkout-routes-panel');
    return panel ? readRoutes(panel) : [];
}

export function initializeCheckoutRoutesPanels() {
    document.querySelectorAll('.flow-checkout-routes-panel').forEach(function (panel) {
        var routes = [];
        try { routes = JSON.parse(panel.dataset.checkoutRoutes || '[]'); } catch (e) {}
        renderCheckoutRoutesPanel(panel, routes);
    });
}

export function handleCheckoutRoutesClick(e) {
    var panel = e.target.closest('.flow-checkout-routes-panel');
    if (!panel) return false;

    if (e.target.closest('.flow-checkout-route-add')) {
        var existing = readRoutes(panel);
        var slots = existing[0] ? existing[0].links.map(function (link) {
            return { n: link.n, destination_id: '' };
        }) : [{ n: 1, destination_id: '' }];
        existing.push({ network_id: '', weight: 0, links: slots });
        renderCheckoutRoutesPanel(panel, existing);
        redistributeWeights(panel);
        return true;
    }

    var removeRoute = e.target.closest('.flow-checkout-route-remove');
    if (removeRoute) {
        removeRoute.closest('.flow-checkout-route').remove();
        redistributeWeights(panel);
        updateLegacyLinksState(panel);
        return true;
    }

    if (e.target.closest('.flow-checkout-slot-add')) {
        var ns = Array.prototype.map.call(panel.querySelectorAll('.flow-checkout-slot-row'), function (row) {
            return parseInt(row.dataset.n, 10);
        }).filter(Number.isFinite);
        var uniqueNs = Array.from(new Set(ns));
        if (uniqueNs.length >= 20) return true;
        var n = ns.length ? Math.max.apply(null, ns) + 1 : 1;
        panel.querySelectorAll('.flow-checkout-route').forEach(function (route) {
            var networkId = route.querySelector('.flow-checkout-route-network').value;
            route.querySelector('.flow-checkout-route-slots').appendChild(createSlotRow(n, networkId, ''));
        });
        return true;
    }

    var removeSlot = e.target.closest('.flow-checkout-slot-remove');
    if (removeSlot) {
        var nToRemove = removeSlot.closest('.flow-checkout-slot-row').dataset.n;
        panel.querySelectorAll('.flow-checkout-slot-row[data-n="' + nToRemove + '"]').forEach(function (row) { row.remove(); });
        return true;
    }
    return false;
}

export function handleCheckoutRoutesChange(e) {
    var network = e.target.closest('.flow-checkout-route-network');
    if (!network) return false;
    var route = network.closest('.flow-checkout-route');
    route.querySelectorAll('.flow-checkout-slot-row').forEach(function (row) {
        var previous = row.querySelector('.flow-checkout-route-destination');
        previous.replaceWith(createDestinationSelect(network.value, ''));
    });
    return true;
}
