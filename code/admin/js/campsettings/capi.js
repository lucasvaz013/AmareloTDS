// ── Meta Conversions API settings ──
// The rows are rendered server-side and fixed to the built-in Lead and Purchase
// statuses, so this only has to read them back.
(function () {
    const container = document.getElementById('capi_container');
    if (!container) {
        return;
    }

    window.collectCapiData = function () {
        return {
            enabled: document.getElementById('capi-enabled')?.checked === true,
            pixel_id: (document.getElementById('capi-pixel-id')?.value || '').trim(),
            access_token: (document.getElementById('capi-access-token')?.value || '').trim(),
            test_event_code: (document.getElementById('capi-test-event-code')?.value || '').trim(),
            map: Array.from(container.querySelectorAll('[data-capi-rule]'))
                .map((rule) => ({
                    status: rule.dataset.capiStatus || '',
                    event_name: rule.querySelector('[data-capi-event]')?.value || '',
                }))
                .filter((mapping) => mapping.status !== '' && mapping.event_name !== ''),
        };
    };
})();
