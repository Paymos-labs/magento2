'use strict';

// Exercise the actual PHP-rendered script without booting Magento or a browser.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const html = execFileSync('php', ['-r',
    'require "tests/bootstrap.php"; require "tests/ConnectionStatusTest.php"; '
    + '$method = new ReflectionMethod(\\Paymos\\Payment\\Block\\Adminhtml\\System\\Config\\ConnectionStatus::class, "_getElementHtml"); '
    + 'echo $method->invoke(paymos_m2_status_block(), new \\Magento\\Framework\\Data\\Form\\Element\\AbstractElement());',
], { cwd: path.resolve(__dirname, '..'), encoding: 'utf8' });
const script = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)][1][1];

class Element {
    constructor() { this.value = ''; this.disabled = false; this.children = []; this.text = ''; }
    set textContent(value) { this.text = value; this.children = []; }
    get textContent() { return this.text; }
    appendChild(child) { this.children.push(child); }
}

function fixture(search = '') {
    const ids = ['order', 'panel', 'over', 'state', 'operations', 'order-summary'];
    const elements = Object.fromEntries(ids.map(id => [id, new Element()]));
    elements.over.disabled = true;
    const requests = [];
    const pending = [];
    vm.runInNewContext(script, {
        URLSearchParams,
        window: { FORM_KEY: 'test-form-key' },
        location: { search },
        document: {
            getElementById: id => elements[id.replace('paymos-refund-', '')],
            createElement: () => new Element(),
        },
        fetch: (url, options) => {
            requests.push(Object.fromEntries(options.body));
            return new Promise((resolve, reject) => pending.push({ resolve, reject }));
        },
    });
    return { ...elements, requests, pending };
}

function panel(id = 42) {
    return {
        order_id: id, order_increment_id: '1000000' + id, order_amount: '100.0000', order_currency: 'USD',
        request_token: 'request-panel-token', can_overpayment: true,
        operations: [{ mode: 'overpayment', operation_id: 'operation-1', request_token: 'request-resume-token' }],
    };
}

async function respond(f, data) {
    f.pending.shift().resolve({ json: async () => data });
    await new Promise(setImmediate);
}

async function load(f) {
    f.order.value = '42';
    f.panel.onclick();
    await respond(f, panel());
}

test('displays loaded order number and amount before refunding that exact id', async () => {
    const f = fixture();
    await load(f);
    assert.equal(f['order-summary'].textContent, '#100000042 · 100.0000 USD');
    assert.equal(f.over.disabled, false);
    f.over.onclick();
    assert.deepEqual(f.requests[1], {
        form_key: 'test-form-key', order_id: '42', action: 'overpayment', request_token: 'request-panel-token',
    });
    assert.equal(f.over.disabled, true);
    assert.equal(f.operations.children[0].disabled, true);
    await respond(f, { message: 'Current refund status is below.' });
    assert.equal(f.over.disabled, true);
});

test('manual field changes block overpayment and resume even without an input event', async () => {
    for (const action of ['overpayment', 'resume']) {
        const f = fixture();
        await load(f);
        const resume = f.operations.children[0];
        f.order.value = '43';
        if (action === 'overpayment') f.over.onclick(); else resume.onclick();
        assert.equal(f.requests.length, 1);
        assert.equal(f.over.disabled, true);
        assert.equal(f['order-summary'].textContent, '');
    }
});

test('input invalidation removes identity and rejects saved resume handlers', async () => {
    const f = fixture();
    await load(f);
    const resume = f.operations.children[0];
    f.order.value = '43';
    f.order.oninput();
    f.order.value = '42';
    resume.onclick();
    f.over.onclick();
    assert.equal(f.requests.length, 1);
    assert.equal(f.operations.children.length, 0);
    assert.equal(f['order-summary'].textContent, '');
});

test('a load response arriving after editing the field cannot arm either action', async () => {
    const f = fixture();
    f.order.value = '42';
    f.panel.onclick();
    f.order.value = '43';
    f.order.oninput();
    await respond(f, panel());
    f.over.onclick();
    assert.equal(f.requests.length, 1);
    assert.equal(f.over.disabled, true);
    assert.equal(f.operations.children.length, 0);
    assert.equal(f['order-summary'].textContent, '');
});

test('a response for a different order or without displayed money cannot arm a refund', async () => {
    for (const data of [panel(43), { ...panel(), order_amount: undefined }]) {
        const f = fixture();
        f.order.value = '42';
        f.panel.onclick();
        await respond(f, data);
        f.over.onclick();
        assert.equal(f.requests.length, 1);
        assert.equal(f.over.disabled, true);
    }
});

test('overlapping loads and double clicks cannot start another request', async () => {
    const f = fixture();
    f.order.value = '42';
    f.panel.onclick();
    f.panel.onclick();
    assert.equal(f.requests.length, 1);
    await respond(f, panel());
    f.over.onclick();
    f.over.onclick();
    assert.equal(f.requests.length, 2);
    await respond(f, { message: 'Current refund status is below.' });
});

test('resume uses the loaded order and existing operation token', async () => {
    const f = fixture();
    await load(f);
    f.operations.children[0].onclick();
    assert.equal(f.requests[1].order_id, '42');
    assert.equal(f.requests[1].action, 'resume');
    assert.equal(f.requests[1].request_token, 'request-resume-token');
    await respond(f, {});
});

test('failed load does not reuse the previous order identity or token', async () => {
    const f = fixture();
    await load(f);
    f.panel.onclick();
    await respond(f, { error: 'failed' });
    f.over.onclick();
    assert.equal(f.requests.length, 2);
    assert.equal(f.over.disabled, true);
    assert.equal(f['order-summary'].textContent, '');
});

test('order selected in redirect URL still requires a completed load before action', async () => {
    const f = fixture('?paymos_order_id=42');
    assert.equal(f.requests[0].action, 'panel');
    assert.equal(f.over.disabled, true);
    await respond(f, panel());
    assert.equal(f.over.disabled, false);
    assert.equal(f['order-summary'].textContent, '#100000042 · 100.0000 USD');
});
