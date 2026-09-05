(function (global) {
  'use strict';

  const transport = global.__yellowTdsEventTransport;
  if (!transport) {
    return;
  }

  let observer = null;
  let pending = false;
  let tracked = false;

  function isVisible(element) {
    if (!element || element.isConnected === false || element.hasAttribute('hidden')) {
      return false;
    }

    let current = element;
    while (current && current.nodeType === 1) {
      const style = global.getComputedStyle(current);
      if (
        style.display === 'none'
        || style.visibility === 'hidden'
        || style.visibility === 'collapse'
        || Number(style.opacity) === 0
      ) {
        return false;
      }
      current = current.parentElement;
    }
    return true;
  }

  function candidates() {
    const explicit = Array.from(document.querySelectorAll('[data-ytds-offer]'));
    if (explicit.length > 0) {
      return explicit;
    }

    const conventional = Array.from(document.querySelectorAll('.delay-hidden'));
    return conventional.length === 1 ? conventional : [];
  }

  function check() {
    if (tracked || pending) {
      return;
    }

    const visibleOffer = candidates().find(isVisible);
    if (!visibleOffer) {
      return;
    }

    pending = true;
    transport.sendEvent('offer_revealed').then(function () {
      pending = false;
      tracked = true;
      if (observer) {
        observer.disconnect();
      }
      global.removeEventListener('resize', check);
      global.removeEventListener('transitionend', check, true);
      global.removeEventListener('animationend', check, true);
    }).catch(function () {
      pending = false;
    });
  }

  function start() {
    observer = new MutationObserver(check);
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['class', 'style', 'hidden'],
      childList: true,
      subtree: true
    });
    global.addEventListener('resize', check);
    global.addEventListener('transitionend', check, true);
    global.addEventListener('animationend', check, true);
    check();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
})(window);
