'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const scriptsDirectory = path.resolve(__dirname, '../../../code/scripts');

function loadSource(name, replacements = {}) {
  let source = fs.readFileSync(path.join(scriptsDirectory, name), 'utf8');
  Object.entries(replacements).forEach(([placeholder, value]) => {
    source = source.split(placeholder).join(JSON.stringify(value));
  });
  return source;
}

function trackedTransport() {
  const calls = [];
  return {
    calls,
    transport: {
      sendEvent(name) {
        calls.push(name);
        return Promise.resolve({ ok: true });
      }
    }
  };
}

function offerHarness({ explicit = [], conventional = [], rejectFirst = false } = {}) {
  const tracking = trackedTransport();
  if (rejectFirst) {
    tracking.transport.sendEvent = function (name) {
      tracking.calls.push(name);
      if (tracking.calls.length === 1) {
        return Promise.reject(new Error('temporary failure'));
      }
      return Promise.resolve({ ok: true });
    };
  }
  let mutationCallback = null;
  class FakeMutationObserver {
    constructor(callback) {
      mutationCallback = callback;
    }
    disconnect() {}
    observe() {}
  }
  const listeners = new Map();
  const window = {
    __yellowTdsEventTransport: tracking.transport,
    addEventListener(name, callback) {
      listeners.set(name, callback);
    },
    removeEventListener(name) {
      listeners.delete(name);
    },
    getComputedStyle(element) {
      return element.style;
    }
  };
  const document = {
    documentElement: {},
    readyState: 'complete',
    addEventListener() {},
    querySelectorAll(selector) {
      return selector === '[data-ytds-offer]' ? explicit : conventional;
    }
  };
  vm.runInNewContext(loadSource('offerrevealedtracking.js'), {
    Array,
    MutationObserver: FakeMutationObserver,
    Number,
    document,
    window
  });
  return { calls: tracking.calls, mutate: () => mutationCallback(), listeners };
}

function offerElement(style = {}) {
  return {
    isConnected: true,
    nodeType: 1,
    parentElement: null,
    style: {
      display: 'block',
      opacity: '1',
      visibility: 'visible',
      ...style
    },
    hasAttribute() {
      return false;
    }
  };
}

test('offer collector tracks an explicit marker only after it becomes visible', () => {
  const offer = offerElement({ display: 'none' });
  const harness = offerHarness({ explicit: [offer], conventional: [offerElement()] });
  assert.deepEqual(harness.calls, []);

  offer.style.display = 'block';
  harness.mutate();
  harness.mutate();
  assert.deepEqual(harness.calls, ['offer_revealed']);
});

test('offer collector falls back only to exactly one delay-hidden element', () => {
  const one = offerHarness({ conventional: [offerElement()] });
  assert.deepEqual(one.calls, ['offer_revealed']);

  const ambiguous = offerHarness({ conventional: [offerElement(), offerElement()] });
  assert.deepEqual(ambiguous.calls, []);
});

test('offer collector treats a hidden ancestor as hidden', () => {
  const parent = offerElement({ visibility: 'hidden' });
  const offer = offerElement();
  offer.parentElement = parent;
  const harness = offerHarness({ explicit: [offer] });
  assert.deepEqual(harness.calls, []);

  parent.style.visibility = 'visible';
  harness.mutate();
  assert.deepEqual(harness.calls, ['offer_revealed']);
});

test('offer collector rechecks visibility after a CSS transition', () => {
  const offer = offerElement({ opacity: '0' });
  const harness = offerHarness({ explicit: [offer] });
  assert.deepEqual(harness.calls, []);

  offer.style.opacity = '1';
  harness.listeners.get('transitionend')();
  assert.deepEqual(harness.calls, ['offer_revealed']);
});

test('offer collector remains observable after a failed transport', async () => {
  const offer = offerElement();
  const harness = offerHarness({ explicit: [offer], rejectFirst: true });
  assert.deepEqual(harness.calls, ['offer_revealed']);

  await Promise.resolve();
  await Promise.resolve();
  harness.mutate();
  assert.deepEqual(harness.calls, ['offer_revealed', 'offer_revealed']);
});

function checkoutHarness(urls) {
  const tracking = trackedTransport();
  const listeners = new Map();
  const document = {
    baseURI: 'https://landing.example/',
    addEventListener(name, callback) {
      listeners.set(name, callback);
    }
  };
  const window = { __yellowTdsEventTransport: tracking.transport };
  vm.runInNewContext(loadSource('checkoutclicktracking.js', {
    '{CHECKOUT_URLS_JSON}': urls
  }), { Array, Set, URL, document, window });
  return {
    calls: tracking.calls,
    dispatch(type, control, options = {}) {
      const event = {
        defaultPrevented: options.defaultPrevented === true,
        target: { closest: () => control }
      };
      listeners.get(type)(event);
      return event;
    }
  };
}

function checkoutControl({ href = '', marked = false } = {}) {
  return {
    href,
    getAttribute(name) {
      return name === 'href' && href ? href : null;
    },
    hasAttribute(name) {
      return name === 'data-ytds-checkout' && marked;
    }
  };
}

test('checkout collector matches resolved URLs while ignoring hashes', async () => {
  const harness = checkoutHarness(['https://checkout.example/order?cid=1']);
  harness.dispatch('click', checkoutControl({
    href: 'https://checkout.example/order?cid=1#products'
  }));
  await Promise.resolve();
  assert.deepEqual(harness.calls, ['checkout_click']);
});

test('checkout collector supports explicit markers and ignores unrelated links', async () => {
  const marked = checkoutHarness([]);
  marked.dispatch('click', checkoutControl({ marked: true }));
  await Promise.resolve();
  assert.deepEqual(marked.calls, ['checkout_click']);

  const unrelated = checkoutHarness(['https://checkout.example/order']);
  unrelated.dispatch('click', checkoutControl({ href: 'https://example.com/privacy' }));
  await Promise.resolve();
  assert.deepEqual(unrelated.calls, []);
});

test('checkout collector ignores interactions cancelled after the capture listener', async () => {
  const harness = checkoutHarness(['https://checkout.example/order']);
  const event = harness.dispatch(
    'auxclick',
    checkoutControl({ href: 'https://checkout.example/order' })
  );
  event.defaultPrevented = true;
  await Promise.resolve();
  assert.deepEqual(harness.calls, []);
});
