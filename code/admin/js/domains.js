// ── Domains page ──
(function () {
    const configNode = document.getElementById('domainsConfig');
    if (!configNode) {
        return;
    }

    let config;
    try {
        config = JSON.parse(configNode.textContent || '{}');
    } catch (error) {
        return;
    }

    const endpoint = config.endpoint || 'domainseditor.php';
    const prefix = config.prefix || 'ytds';
    const alertBox = document.getElementById('domainsAlert');
    const el = (id) => document.getElementById(id);

    let revision = 0;
    let state = {};

    function showAlert(message, isError) {
        alertBox.textContent = message || '';
        alertBox.classList.toggle('is-error', isError === true);
        alertBox.hidden = !message;
    }

    function setResult(id, message, isError) {
        const node = el(id);
        if (!node) {
            return;
        }
        node.textContent = message || '';
        node.classList.toggle('is-error', isError === true);
    }

    function renderSteps(id, steps) {
        const node = el(id);
        if (!node) {
            return;
        }
        node.innerHTML = '';
        if (!steps || steps.length === 0) {
            node.hidden = true;
            return;
        }
        steps.forEach((step) => {
            const li = document.createElement('li');
            li.className = step.ok ? 'is-ok' : 'is-error';
            li.textContent = step.message;
            node.appendChild(li);
        });
        node.hidden = false;
    }

    function setBusy(busy) {
        document.querySelectorAll('.domains-content button').forEach((button) => {
            if (busy) {
                button.dataset.wasDisabled = button.disabled ? '1' : '0';
                button.disabled = true;
            } else if (button.dataset.wasDisabled !== undefined) {
                button.disabled = button.dataset.wasDisabled === '1';
                delete button.dataset.wasDisabled;
            }
        });
        if (!busy) {
            applyState();
        }
    }

    // The buttons are driven entirely by what the server says is possible, so a missing
    // integration greys out the control and the help icon explains why.
    function applyState() {
        el('domainsPublicIp').textContent = state.public_ip || 'could not be detected';
        el('domainsPrefixSample').textContent = prefix + '.yourdomain.com';
        el('manualNameSample').textContent = prefix;
        el('manualHostSample').textContent = prefix + '.yourdomain.com';
        el('manualIpSample').textContent = state.public_ip || 'this server';

        const canRegister = state.can_register === true;
        el('registerDomainBtn').disabled = !canRegister;
        el('registerState').textContent = canRegister ? 'ready' : 'blocked';
        el('registerState').className = 'domain-state ' + (canRegister ? 'is-ok' : 'is-error');

        const reasons = [];
        if (!state.namecheap_ready) {
            reasons.push('the Namecheap integration');
        }
        if (!state.cloudflare_ready) {
            reasons.push('the Cloudflare integration');
        }
        const missingContacts = state.registrant_source === 'none' && state.namecheap_ready;

        const blocked = el('registerBlocked');
        blocked.hidden = canRegister;
        const link = el('registerBlockedLink');
        if (!canRegister) {
            if (missingContacts) {
                // Nothing to fix under Integrations in this case, so point at the
                // fallback further down this same page instead.
                el('registerBlockedText').textContent = state.registrant_message
                    || 'Namecheap did not supply contact details for this account.';
                link.textContent = 'Fill the fallback profile';
                link.setAttribute('href', '#registrantCard');
            } else {
                el('registerBlockedText').textContent = 'Registering needs ' + reasons.join(' and ') + '.';
                link.textContent = 'Open Integrations';
                link.setAttribute('href', 'integrations.php');
            }
            el('registerBlockedHelp').setAttribute(
                'data-tooltip',
                missingContacts
                    ? 'Registration uses the contact details already stored in your Namecheap account. This account has none usable, so either add a contact profile there or fill the fallback on this page.'
                    : 'Missing: ' + reasons.join(', ') + '. Registration buys a domain at Namecheap and moves its DNS to Cloudflare, so both have to be working first.'
            );
        }

        const source = el('registrantSource');
        if (state.registrant_source === 'namecheap') {
            source.textContent = 'Contact details come from your Namecheap account' + (state.registrant_label ? ' (' + state.registrant_label + ')' : '') + '.';
        } else if (state.registrant_source === 'local') {
            source.textContent = 'Contact details come from the fallback profile saved on this page.';
        } else {
            source.textContent = '';
        }

        const cfReady = state.cloudflare_ready === true;
        const syncBtn = el('syncBtn');
        syncBtn.disabled = !cfReady;
        el('syncState').textContent = cfReady ? 'ready' : 'needs Cloudflare';
        el('syncState').className = 'domain-state ' + (cfReady ? 'is-ok' : 'is-error');
        // Reuses the panel's existing help-cursor tooltip rather than inventing another.
        const wrap = el('syncWrap');
        wrap.classList.toggle('settings-help-icon', !cfReady);
        wrap.classList.toggle('is-blocked', !cfReady);
        if (cfReady) {
            wrap.removeAttribute('data-tooltip');
            wrap.removeAttribute('tabindex');
        } else {
            wrap.setAttribute('tabindex', '0');
            wrap.setAttribute(
                'data-tooltip',
                'Connect Cloudflare first, under Integrations. Sync uses that API to create ' + prefix + '.yourdomain.com pointing at this server.'
            );
        }

        const gatewayButton = el('postbackGatewayBtn');
        gatewayButton.disabled = !cfReady;
        el('postbackGatewayState').textContent = cfReady ? 'ready' : 'needs Cloudflare';
        el('postbackGatewayState').className = 'domain-state ' + (cfReady ? 'is-ok' : 'is-error');
        const gatewayWrap = el('postbackGatewayWrap');
        gatewayWrap.classList.toggle('settings-help-icon', !cfReady);
        gatewayWrap.classList.toggle('is-blocked', !cfReady);
        if (cfReady) {
            gatewayWrap.removeAttribute('data-tooltip');
            gatewayWrap.removeAttribute('tabindex');
        } else {
            gatewayWrap.setAttribute('tabindex', '0');
            gatewayWrap.setAttribute('data-tooltip', 'Connect Cloudflare first. Gateway creation updates the root A record.');
        }

        renderDomains(state.domains || []);
        renderPostbackGateways(state.postback_gateways || []);
    }

    const ORIGINS = { registered: 'bought here', cloudflare: 'Cloudflare sync', manual: 'manual' };

    function statusCell(entry) {
        const td = document.createElement('td');
        const badge = document.createElement('span');
        const status = entry.status || 'checking';

        if (status === 'ready') {
            badge.className = 'domain-badge is-ready';
            badge.innerHTML = '<i class="bi bi-check-circle-fill"></i> ready';
        } else if (status === 'error') {
            badge.className = 'domain-badge is-error';
            badge.innerHTML = '<i class="bi bi-exclamation-circle-fill"></i> needs attention';
        } else {
            badge.className = 'domain-badge is-checking';
            badge.innerHTML = '<i class="bi bi-arrow-repeat domain-spin"></i> checking';
        }

        // The reason lives in the tooltip the panel already uses elsewhere, so the row
        // stays short but the detail is one hover away.
        if (entry.detail) {
            badge.classList.add('settings-help-icon');
            badge.setAttribute('tabindex', '0');
            badge.setAttribute('data-tooltip', entry.detail);
        }
        td.appendChild(badge);
        return td;
    }

    function renderDomains(list) {
        const body = el('domainsTableBody');
        body.innerHTML = '';
        el('domainsEmpty').hidden = list.length > 0;
        el('domainsTable').hidden = list.length === 0;

        list.forEach((entry) => {
            const tr = document.createElement('tr');
            [entry.hostname, entry.name, ORIGINS[entry.source] || entry.source].forEach((text) => {
                const td = document.createElement('td');
                td.textContent = text;
                tr.appendChild(td);
            });
            tr.appendChild(statusCell(entry));

            const actions = document.createElement('td');
            actions.className = 'domains-actions';

            if ((entry.status || 'checking') !== 'ready') {
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'btn btn-outline-secondary btn-sm';
                retry.textContent = 'Check now';
                retry.addEventListener('click', () => run({ action: 'resume', domain: entry.name }));
                actions.appendChild(retry);
            }

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-outline-danger btn-sm';
            remove.textContent = 'Remove';
            remove.addEventListener('click', () => {
                if (window.confirm('Remove ' + entry.name + ' from the list? The DNS record stays as it is.')) {
                    run({ action: 'remove', domain: entry.name });
                }
            });
            actions.appendChild(remove);
            tr.appendChild(actions);
            body.appendChild(tr);
        });

        schedulePendingSweep(list);
    }

    function renderPostbackGateways(list) {
        const body = el('postbackGatewaysTableBody');
        body.innerHTML = '';
        el('postbackGatewaysEmpty').hidden = list.length > 0;
        el('postbackGatewaysTable').hidden = list.length === 0;

        list.forEach((entry) => {
            const tr = document.createElement('tr');
            const endpoint = document.createElement('td');
            const code = document.createElement('code');
            code.textContent = entry.url || ('https://' + entry.name + '/api/postback.php');
            endpoint.appendChild(code);
            tr.appendChild(endpoint);
            tr.appendChild(statusCell(entry));

            const actions = document.createElement('td');
            actions.className = 'domains-actions';
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'btn btn-outline-secondary btn-sm';
            retry.textContent = 'Check now';
            retry.addEventListener('click', () => run({ action: 'gateway-resume', domain: entry.name }));
            actions.appendChild(retry);

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-outline-danger btn-sm';
            remove.textContent = 'Remove';
            remove.addEventListener('click', () => {
                if (window.confirm('Stop reconciling ' + entry.name + '? DNS and nginx will stay in place.')) {
                    run({ action: 'gateway-remove', domain: entry.name });
                }
            });
            actions.appendChild(remove);
            tr.appendChild(actions);
            body.appendChild(tr);
        });
    }

    // Cloudflare activates a zone minutes to hours after the nameservers change, so the
    // panel keeps nudging it while anything is unfinished. The cron does the same with
    // the panel closed; this just makes the wait visible.
    let sweepTimer = null;
    function schedulePendingSweep(list) {
        const pending = list.some((entry) => (entry.status || 'checking') !== 'ready');
        if (sweepTimer) {
            clearTimeout(sweepTimer);
            sweepTimer = null;
        }
        if (!pending) {
            return;
        }
        sweepTimer = setTimeout(() => {
            run({ action: 'resume-pending' });
        }, 5 * 60 * 1000);
    }

    async function request(body) {
        const response = await fetch(endpoint, {
            method: body ? 'POST' : 'GET',
            headers: body ? { 'Content-Type': 'application/json' } : undefined,
            body: body ? JSON.stringify(Object.assign({ revision: revision }, body)) : undefined,
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(payload.error || 'Request failed (' + response.status + ').');
        }
        return payload;
    }

    async function run(body, onPayload) {
        setBusy(true);
        showAlert('');
        try {
            const payload = await request(body);
            revision = payload.revision || revision;
            state = payload;
            if (payload.message) {
                showAlert(payload.message, false);
            }
            if (onPayload) {
                onPayload(payload);
            }
        } catch (error) {
            showAlert(error.message, true);
        } finally {
            setBusy(false);
        }
    }

    el('checkAvailability').addEventListener('click', () => {
        const domain = el('registerDomain').value.trim();
        run({ action: 'check-availability', domain: domain }, (payload) => {
            setResult('registerResult', payload.step ? payload.step.message : '', payload.step ? !payload.step.ok : true);
        });
    });

    el('registerDomainBtn').addEventListener('click', () => {
        const domain = el('registerDomain').value.trim();
        const years = el('registerYears').value;
        if (!window.confirm('Register ' + domain + ' for ' + years + ' year(s)?\n\nThis charges your Namecheap balance and cannot be undone.')) {
            return;
        }
        run({ action: 'register', domain: domain, years: Number(years), confirm: true }, (payload) => {
            const outcome = payload.outcome || {};
            setResult('registerResult', outcome.message, !outcome.ok);
            renderSteps('registerSteps', outcome.steps);
        });
    });

    el('syncBtn').addEventListener('click', () => {
        run({ action: 'cloudflare-sync', domain: el('syncDomain').value.trim() }, (payload) => {
            const outcome = payload.outcome || {};
            setResult('syncResult', outcome.message, !outcome.ok);
            renderSteps('syncSteps', outcome.steps);
        });
    });

    el('postbackGatewayBtn').addEventListener('click', () => {
        const domain = el('postbackGatewayDomain').value.trim();
        if (!window.confirm(
            'Create a postback-only gateway on ' + domain + '?\n\n'
            + 'This replaces apex address records and can take an existing website offline.'
        )) {
            return;
        }
        run({ action: 'gateway-sync', domain: domain, confirm: true }, (payload) => {
            const outcome = payload.outcome || {};
            setResult('postbackGatewayResult', outcome.message, outcome.status === 'error');
            renderSteps('postbackGatewaySteps', outcome.steps);
        });
    });

    el('manualToggle').addEventListener('click', () => {
        const panel = el('manualPanel');
        panel.hidden = !panel.hidden;
    });

    el('manualCheckBtn').addEventListener('click', () => {
        run({ action: 'manual-check', domain: el('manualDomain').value.trim() }, (payload) => {
            const outcome = payload.outcome || {};
            setResult('manualResult', outcome.message, !outcome.ok);
        });
    });

    el('registrantToggle').addEventListener('click', () => {
        const panel = el('registrantPanel');
        panel.hidden = !panel.hidden;
    });

    el('registrantSave').addEventListener('click', () => {
        const registrant = {};
        document.querySelectorAll('[data-registrant]').forEach((input) => {
            registrant[input.dataset.registrant] = input.value.trim();
        });
        run({ action: 'save-registrant', registrant: registrant });
    });

    run(null);
})();
