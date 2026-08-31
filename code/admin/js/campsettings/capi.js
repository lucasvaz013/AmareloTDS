// ── Meta Conversions API settings ──
(function () {
    const mapContainer = document.getElementById('capi_container');
    const container = document.getElementById('capi-pixels');
    const template = document.getElementById('capi-pixel-template');
    const addButton = document.getElementById('add-capi-pixel');
    if (!mapContainer || !container || !template || !addButton) {
        return;
    }

    const maxPixels = Number(container.dataset.maxPixels || 20);
    const pixelRows = () => Array.from(container.querySelectorAll('[data-capi-pixel]'));

    function refreshPixelCards() {
        const rows = pixelRows();
        rows.forEach((row, index) => {
            const number = index + 1;
            const title = row.querySelector('[data-capi-pixel-title]');
            const remove = row.querySelector('[data-remove-capi-pixel]');
            if (title) title.textContent = `Pixel ${number}`;
            if (remove) remove.setAttribute('aria-label', `Remove Pixel ${number}`);
        });
        addButton.disabled = rows.length >= maxPixels;
    }

    addButton.addEventListener('click', () => {
        if (pixelRows().length >= maxPixels) return;
        container.appendChild(template.content.cloneNode(true));
        refreshPixelCards();
    });

    container.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-remove-capi-pixel]');
        if (!remove) return;
        remove.closest('[data-capi-pixel]')?.remove();
        refreshPixelCards();
    });

    window.collectCapiData = function () {
        const pixels = pixelRows()
            .map((row) => ({
                pixel_id: (row.querySelector('[data-capi-pixel-id]')?.value || '').trim(),
                access_token: (row.querySelector('[data-capi-access-token]')?.value || '').trim(),
                test_event_code: (row.querySelector('[data-capi-test-event-code]')?.value || '').trim(),
            }))
            .filter((pixel) => pixel.pixel_id !== '' || pixel.access_token !== '' || pixel.test_event_code !== '');
        const primary = pixels[0] || {pixel_id: '', access_token: '', test_event_code: ''};

        return {
            enabled: document.getElementById('capi-enabled')?.checked === true,
            pixel_id: primary.pixel_id,
            access_token: primary.access_token,
            test_event_code: primary.test_event_code,
            pixels: pixels,
            map: Array.from(mapContainer.querySelectorAll('[data-capi-rule]'))
                .map((rule) => ({
                    status: rule.dataset.capiStatus || '',
                    event_name: rule.querySelector('[data-capi-event]')?.value || '',
                }))
                .filter((mapping) => mapping.status !== '' && mapping.event_name !== ''),
        };
    };

    refreshPixelCards();
})();
