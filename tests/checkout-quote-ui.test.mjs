import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const blade = readFileSync(new URL('../resources/views/checkout/checkout.blade.php', import.meta.url), 'utf8');
const code = blade.split("@push('scripts')")[1].match(/<script>([\s\S]*?)<\/script>/)[1]
    .replace("@json(route('checkout.quote'))", "'/checkout/quote'")
    .replace('@json($canAcceptCardPayments)', 'false')
    .replace('@json($defaultPaymentMethod)', "'stripe'");

function harness(responses) {
    let ready, timer;
    const elements = new Map();
    function element(id) {
        if (!elements.has(id)) elements.set(id, { textContent: '', value: '', disabled: false, handlers: {},
            classList: {toggle() {}, add() {}, remove() {}},
            addEventListener(event, fn) { this.handlers[event] = fn; },
            querySelectorAll() { return [element('shipping_city')]; },
        });
        return elements.get(id);
    }
    vm.runInNewContext(code, {
        document: {getElementById: element, addEventListener(event, fn) { ready = fn; }},
        FormData: class {},
        fetch: async () => responses.shift(),
        setTimeout(fn) { timer = fn; return 1; }, clearTimeout() {},
    });
    ready();
    return {element, update: () => timer(), change: () => element('shipping_city').handlers.input()};
}

const ok = data => ({ok: true, json: async () => data});
const total = amount => ({currency: 'ZAR', subtotal: 100, discount: 10, shipping: 15, tax: 6.3, total: amount});

test('quote labels display server totals in ZAR and preserve the shipping label', async () => {
    const page = harness([ok(total(111.3))]);
    await page.update();
    assert.match(page.element('total-amount').textContent, /ZAR 111[.,]30/);
    assert.match(page.element('quote-tax').textContent, /6[.,]30/);
    assert.match(page.element('quote-shipping').textContent, /15[.,]00/);
    assert.equal(page.element('submit-button').disabled, true);
});

test('a fully discounted order can complete without a configured payment gateway', async () => {
    const page = harness([ok(total(0))]);
    await page.update();
    assert.equal(page.element('submit-button').disabled, false);
    assert.equal(page.element('submit-button').textContent, 'Complete Order');
});

test('invalid delivery details prevent submission instead of using stale totals', async () => {
    const page = harness([{ok: false, json: async () => ({errors: {shipping_country: ['Unsupported destination']}})}]);
    await page.update();
    assert.match(page.element('quote-status').textContent, /Unsupported destination/);
    let prevented = false, stopped = false;
    page.element('checkout-form').handlers.submit({preventDefault() {prevented = true;}, stopImmediatePropagation() {stopped = true;}});
    assert.equal(prevented, true);
    assert.equal(stopped, true);
});

test('late responses cannot overwrite a newer address quote', async () => {
    let resolve;
    const slow = {ok: true, json: () => new Promise(done => {resolve = done;})};
    const page = harness([slow, ok(total(140))]);
    const first = page.update();
    await Promise.resolve();
    page.change();
    await page.update();
    resolve(total(111.3));
    await first;
    assert.match(page.element('total-amount').textContent, /ZAR 140[.,]00/);
});
