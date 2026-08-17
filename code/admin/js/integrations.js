// ── Integrations page ──
(function () {
    const configNode = document.getElementById('integrationsConfig');
    if (!configNode) {
        return;
    }

    let config;
    try {
        config = JSON.parse(configNode.textContent || '{}');
    } catch (error) {
        return;
    }

    const endpoint = config.endpoint || 'integrationseditor.php';
    const alertBox = document.getElementById('integrationsAlert');
    const publicIpNode = document.getElementById('integrationsPublicIp');
    const saveButton = document.getElementById('integrationsSave');
    const verifyButton = document.getElementById('integrationsVerify');

    let revision = 0;

    function showAlert(message, isError) {
        if (!alertBox) {
            return;
        }
        alertBox.textContent = message;
        alertBox.classList.toggle('is-error', isError === true);
        alertBox.hidden = message === '';
    }

    function setBusy(busy) {
        [saveButton, verifyButton].forEach((button) => {
            if (button) {
                button.disabled = busy === true;
            }
        });
    }

    function setPublicIp(ip) {
        if (publicIpNode) {
            publicIpNode.textContent = ip || 'could not be detected';
        }
    }

    function renderResults(results) {
        if (!results) {
            return;
        }
        Object.keys(results).forEach((service) => {
            const card = document.querySelector('[data-integration="' + service + '"]');
            if (!card) {
                return;
            }
            const result = results[service] || {};
            const state = card.querySelector('[data-integration-state]');
            const message = card.querySelector('[data-integration-result]');

            let label = 'not configured';
            let tone = 'is-idle';
            if (result.configured && result.ok) {
                label = 'working';
                tone = 'is-ok';
            } else if (result.configured) {
                label = 'failing';
                tone = 'is-error';
            }

            if (state) {
                state.textContent = label;
                state.classList.remove('is-ok', 'is-error', 'is-idle');
                state.classList.add(tone);
            }
            if (message) {
                message.textContent = result.message || '';
                message.classList.toggle('is-error', result.configured === true && result.ok !== true);
            }
        });
    }

    // Blank secrets are omitted so the server keeps whatever is already stored — the page
    // never received the real values to send back.
    function collectSettings() {
        const settings = {
            namecheapApiUser: (document.getElementById('namecheapApiUser')?.value || '').trim(),
            namecheapUsername: (document.getElementById('namecheapUsername')?.value || '').trim(),
            namecheapSandbox: document.getElementById('namecheapSandbox')?.checked === true,
        };
        ['cloudflareApiToken', 'namecheapApiKey'].forEach((key) => {
            const value = (document.getElementById(key)?.value || '').trim();
            if (value !== '') {
                settings[key] = value;
            }
        });
        return settings;
    }

    function clearSecretInputs() {
        ['cloudflareApiToken', 'namecheapApiKey'].forEach((key) => {
            const input = document.getElementById(key);
            if (input) {
                input.value = '';
            }
        });
    }

    async function request(body) {
        const response = await fetch(endpoint, {
            method: body ? 'POST' : 'GET',
            headers: body ? { 'Content-Type': 'application/json' } : undefined,
            body: body ? JSON.stringify(body) : undefined,
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(payload.error || 'Request failed (' + response.status + ').');
        }
        return payload;
    }

    // Opening the page has to answer "does this work right now?", so the check runs on
    // load rather than only after a save — otherwise navigating away and back shows the
    // placeholder state and looks like the integration stopped working.
    async function load() {
        setBusy(true);
        try {
            const payload = await request({ action: 'verify' });
            revision = payload.revision || 0;
            setPublicIp(payload.public_ip);
            renderResults(payload.results);
        } catch (error) {
            document.querySelectorAll('[data-integration-state]').forEach((node) => {
                node.textContent = 'unknown';
                node.classList.add('is-idle');
            });
            showAlert(error.message, true);
        } finally {
            setBusy(false);
        }
    }

    async function run(body, successMessage) {
        setBusy(true);
        showAlert('');
        try {
            const payload = await request(body);
            revision = payload.revision || revision;
            setPublicIp(payload.public_ip);
            renderResults(payload.results);
            clearSecretInputs();
            showAlert(successMessage, false);
        } catch (error) {
            showAlert(error.message, true);
        } finally {
            setBusy(false);
        }
    }

    saveButton?.addEventListener('click', () => {
        run({ action: 'save', revision: revision, settings: collectSettings() }, 'Saved. Results below.');
    });

    verifyButton?.addEventListener('click', () => {
        run({ action: 'verify' }, 'Checked. Results below.');
    });

    load();
})();
