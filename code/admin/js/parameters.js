(() => {
    const node = (selector) => document.querySelector(selector);

    function openParameters(event) {
        event?.preventDefault();
        $('#parametersModal').modal({
            modalClass: 'ywbmodal parameters-modal',
            fadeDuration: 200,
            showClose: false,
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        node('#openParameters')?.addEventListener('click', openParameters);
        node('#closeParameters')?.addEventListener('click', () => $.modal.close());
        node('#dismissParameters')?.addEventListener('click', () => $.modal.close());
    });
})();
