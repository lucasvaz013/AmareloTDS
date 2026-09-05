(function (global) {
  'use strict';

  const transport = global.__yellowTdsEventTransport;
  if (!transport) {
    return;
  }

  function normalizeUrl(value) {
    try {
      const url = new URL(value, document.baseURI);
      if (url.protocol !== 'http:' && url.protocol !== 'https:') {
        return '';
      }
      url.hash = '';
      return url.href;
    } catch (error) {
      return '';
    }
  }

  const checkoutUrls = new Set(
    (Array.isArray({CHECKOUT_URLS_JSON}) ? {CHECKOUT_URLS_JSON} : [])
      .map(normalizeUrl)
      .filter(Boolean)
  );

  function isCheckout(control) {
    if (control.hasAttribute('data-ytds-checkout')) {
      return true;
    }
    const href = control.getAttribute('href');
    const normalized = href ? normalizeUrl(control.href || href) : '';
    return normalized !== '' && checkoutUrls.has(normalized);
  }

  function track(event) {
    if (event.defaultPrevented || !event.target || typeof event.target.closest !== 'function') {
      return;
    }
    const control = event.target.closest('[data-ytds-checkout], a[href]');
    if (!control || !isCheckout(control)) {
      return;
    }
    transport.sendEvent('checkout_click').catch(function () {});
  }

  function deferTrack(event) {
    Promise.resolve().then(function () {
      track(event);
    });
  }

  document.addEventListener('click', deferTrack, true);
  document.addEventListener('auxclick', deferTrack, true);
})(window);
