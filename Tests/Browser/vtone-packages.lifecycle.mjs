/*
 * DOM/lifecycle acceptance for the shared V-T.ONE licence screen.
 *
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 *
 * Why this exists as an executed test rather than a source assertion:
 *
 * TYPO3 renders a backend module's script into the document head, so the asset
 * runs before the Fluid markup it binds to exists. A file that looks the element
 * up at the top level and returns when it is missing returns permanently — and
 * the screen then shows three buttons that produce no request, no error and no
 * message. Nothing about the rendered HTML, the generated route URLs or a grep
 * for addEventListener reveals that. Only running the real asset in both orders
 * and watching for the request does.
 *
 * So this harness evaluates the shipped file — not a copy, not a transform — in a
 * minimal DOM built to mirror what Packages/Index.html renders, and asserts that
 * every control produces exactly one local request, in both load orders, without
 * duplicates.
 *
 * Run: node Tests/Browser/vtone-packages.lifecycle.mjs
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import vm from 'node:vm';

const HERE = dirname(fileURLToPath(import.meta.url));
// The override exists so the gate can be pointed at a built artefact, or at a
// deliberately broken copy to confirm it still fails when it should.
const ASSET = process.env.VTONE_ASSET
    ? resolve(process.env.VTONE_ASSET)
    : resolve(HERE, '../../Resources/Public/JavaScript/vtone-packages.js');
const SOURCE = readFileSync(ASSET, 'utf8');

/* ── A DOM small enough to read, faithful enough to bind against ───────────── */

const SIMPLE = /^(?:([a-z][a-z0-9-]*)|\.([\w-]+)|#([\w-]+)|\[([\w-]+)(?:="([^"]*)")?\])+$/i;

class El {
    constructor(tag) {
        this.tagName = String(tag).toUpperCase();
        this.attributes = new Map();
        this.children = [];
        this.parentNode = null;
        this.listeners = new Map();
        this.value = '';
        this.disabled = false;
        this._text = '';
    }

    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    getAttribute(name) { return this.attributes.has(name) ? this.attributes.get(name) : null; }
    removeAttribute(name) { this.attributes.delete(name); }

    get id() { return this.getAttribute('id') || ''; }
    set id(value) { this.setAttribute('id', value); }

    get textContent() { return this._text; }
    set textContent(value) { this._text = String(value == null ? '' : value); }

    appendChild(child) {
        child.parentNode = this;
        this.children.push(child);
        return child;
    }

    /** Depth-first, document order. */
    * walk() {
        for (const child of this.children) {
            yield child;
            yield* child.walk();
        }
    }

    matches(selector) {
        return selector.split(',').some((part) => this._matchesOne(part.trim()));
    }

    _matchesOne(selector) {
        if (!selector || !SIMPLE.test(selector)) {
            throw new Error(`unsupported selector in test DOM: ${selector}`);
        }
        for (const token of selector.match(/(?:[a-z][a-z0-9-]*|\.[\w-]+|#[\w-]+|\[[^\]]+\])/gi) || []) {
            if (token.startsWith('.')) {
                const classes = (this.getAttribute('class') || '').split(/\s+/);
                if (!classes.includes(token.slice(1))) { return false; }
            } else if (token.startsWith('#')) {
                if (this.id !== token.slice(1)) { return false; }
            } else if (token.startsWith('[')) {
                const m = token.match(/^\[([\w-]+)(?:="([^"]*)")?\]$/);
                if (!this.attributes.has(m[1])) { return false; }
                if (m[2] !== undefined && this.getAttribute(m[1]) !== m[2]) { return false; }
            } else if (this.tagName !== token.toUpperCase()) {
                return false;
            }
        }
        return true;
    }

    querySelector(selector) {
        for (const node of this.walk()) {
            if (node.matches(selector)) { return node; }
        }
        return null;
    }

    querySelectorAll(selector) {
        const found = [];
        for (const node of this.walk()) {
            if (node.matches(selector)) { found.push(node); }
        }
        return found;
    }

    closest(selector) {
        let node = this;
        while (node) {
            if (node.matches && node.matches(selector)) { return node; }
            node = node.parentNode;
        }
        return null;
    }

    addEventListener(type, handler, options) {
        if (!this.listeners.has(type)) { this.listeners.set(type, []); }
        this.listeners.get(type).push({ handler, once: !!(options && options.once) });
    }

    fire(type, event) {
        const registered = this.listeners.get(type) || [];
        this.listeners.set(type, registered.filter((entry) => !entry.once));
        for (const entry of registered) { entry.handler.call(this, event); }
    }

    /** A real bubbling click, which is what makes delegated binding meaningful. */
    click() {
        if (this.disabled) { return; }
        let defaultPrevented = false;
        const event = { type: 'click', target: this, preventDefault() { defaultPrevented = true; } };
        let node = this;
        while (node) {
            node.fire('click', event);
            node = node.parentNode;
        }
        return defaultPrevented;
    }
}

class Doc extends El {
    constructor() {
        super('#document');
        this.readyState = 'loading';
    }

    getElementById(id) {
        for (const node of this.walk()) {
            if (node.id === id) { return node; }
        }
        return null;
    }
}

/* ── The markup the Fluid template produces ────────────────────────────────── */

function makeCard(slug) {
    const card = new El('section');
    card.setAttribute('class', 'updater-card vtone-package');
    card.setAttribute('data-package', slug);

    const input = new El('input');
    input.setAttribute('data-role', 'key');
    input.id = `vtone-key-${slug}`;
    card.appendChild(input);

    for (const role of ['activate', 'refresh', 'clear']) {
        const button = new El('button');
        button.setAttribute('type', 'button');
        button.setAttribute('class', 'updater-btn');
        button.setAttribute('data-role', role);
        card.appendChild(button);
    }

    const message = new El('span');
    message.setAttribute('data-role', 'message');
    card.appendChild(message);

    return card;
}

function makeScreen(document, { slugs = ['guardian'], island = true, wiring = null } = {}) {
    const root = new El('div');
    root.id = 'vtonePackages';

    if (island) {
        const script = new El('script');
        script.id = 'vtone-packages-config';
        script.textContent = JSON.stringify({
            sections: (wiring || slugs.map((slug) => ({
                slug,
                actions: {
                    status: `/typo3/ajax/${slug}/status?token=t`,
                    activate: `/typo3/ajax/${slug}/activate?token=t`,
                    refresh: `/typo3/ajax/${slug}/refresh?token=t`,
                    clear: `/typo3/ajax/${slug}/clear?token=t`
                }
            })))
        });
        root.appendChild(script);
    }

    for (const slug of slugs) { root.appendChild(makeCard(slug)); }
    document.appendChild(root);

    return root;
}

/* ── The world the asset runs in ───────────────────────────────────────────── */

function makeWorld() {
    const document = new Doc();
    const requests = [];
    const scheduled = [];
    let reloads = 0;
    let answer = { success: true, valid: true };
    let confirmResult = true;
    let failNext = false;

    const window = {
        TYPO3: { lang: {} },
        confirm: () => confirmResult,
        setTimeout: (fn, ms) => { scheduled.push({ fn, ms }); return scheduled.length; },
        location: { reload: () => { reloads += 1; } }
    };

    const fetch = (url, init) => {
        requests.push({ url, init, body: init && init.body ? JSON.parse(init.body) : null });
        if (failNext) { return Promise.reject(new Error('network')); }
        const payload = JSON.stringify(answer);
        return Promise.resolve({ text: () => Promise.resolve(payload) });
    };

    const sandbox = { document, window, fetch, console };
    sandbox.globalThis = sandbox;

    return {
        document,
        window,
        requests,
        scheduled,
        get reloads() { return reloads; },
        setAnswer: (value) => { answer = value; },
        setConfirm: (value) => { confirmResult = value; },
        setFailNext: (value) => { failNext = value; },
        evaluate: () => vm.runInNewContext(SOURCE, sandbox, { filename: ASSET }),
        settle: async () => { for (let i = 0; i < 12; i += 1) { await Promise.resolve(); await new Promise(setImmediate); } }
    };
}

/* ── Assertions ────────────────────────────────────────────────────────────── */

let failures = 0;
let passes = 0;

function check(label, condition, detail = '') {
    if (condition) {
        passes += 1;
        console.log(`  ok   ${label}`);
        return;
    }
    failures += 1;
    console.log(`  FAIL ${label}${detail ? ` — ${detail}` : ''}`);
}

async function scenario(label, body) {
    console.log(`\n${label}`);
    try {
        await body();
    } catch (error) {
        failures += 1;
        console.log(`  FAIL threw — ${error && error.stack ? error.stack : error}`);
    }
}

function control(world, slug, role) {
    return world.document
        .getElementById('vtonePackages')
        .querySelector(`[data-package="${slug}"]`)
        .querySelector(`[data-role="${role}"]`);
}

function message(world, slug) {
    return world.document
        .getElementById('vtonePackages')
        .querySelector(`[data-package="${slug}"]`)
        .querySelector('[data-role="message"]').textContent;
}

/**
 * Stands in for what an accepted operation actually does: the script schedules a
 * reload and deliberately leaves the section busy and its controls disabled,
 * because the server is about to render the state it just stored. The test keeps
 * one DOM, so the section is returned to a freshly rendered condition by hand.
 */
function rerender(world, slug) {
    const card = world.document.getElementById('vtonePackages').querySelector(`[data-package="${slug}"]`);
    card.removeAttribute('data-vtone-busy');
    for (const button of card.querySelectorAll('[data-role="activate"], [data-role="refresh"], [data-role="clear"]')) {
        button.disabled = false;
    }
    world.requests.length = 0;
    world.scheduled.length = 0;
}

/** Every control, once each, in both load orders — the core of the gate. */
async function exerciseEveryControl(world) {
    control(world, 'guardian', 'activate').parentNode.querySelector('[data-role="key"]').value = '  KEY-1234  ';
    control(world, 'guardian', 'activate').click();
    await world.settle();

    check('activate creates exactly one local request', world.requests.length === 1, `saw ${world.requests.length}`);
    check('activate posts to the rendered activate route', world.requests[0]?.url === '/typo3/ajax/guardian/activate?token=t', world.requests[0]?.url);
    check('activate carries the trimmed entered key', world.requests[0]?.body?.key === 'KEY-1234');
    check('activate uses POST', world.requests[0]?.init?.method === 'POST');
    check('an accepted result schedules the server-rendered reload', world.scheduled.length === 1);

    rerender(world, 'guardian');
    control(world, 'guardian', 'refresh').click();
    await world.settle();

    check('refresh creates exactly one local request', world.requests.length === 1, `saw ${world.requests.length}`);
    check('refresh posts to the rendered refresh route', world.requests[0]?.url === '/typo3/ajax/guardian/refresh?token=t');
    check('refresh submits no key from the browser', world.requests[0]?.body && !('key' in world.requests[0].body));

    rerender(world, 'guardian');
    control(world, 'guardian', 'clear').click();
    await world.settle();

    check('remove creates exactly one local request', world.requests.length === 1, `saw ${world.requests.length}`);
    check('remove posts to the rendered removal route', world.requests[0]?.url === '/typo3/ajax/guardian/clear?token=t');
}

/* ── Scenarios ─────────────────────────────────────────────────────────────── */

await scenario('Order 1 — asset evaluated before the module markup exists', async () => {
    const world = makeWorld();
    world.document.readyState = 'loading';
    world.evaluate();                       // head script: nothing to bind to yet
    makeScreen(world.document);             // Fluid body arrives afterwards
    world.document.readyState = 'interactive';
    world.document.fire('DOMContentLoaded', { type: 'DOMContentLoaded' });

    await exerciseEveryControl(world);
});

await scenario('Order 2 — module markup exists before the asset is evaluated', async () => {
    const world = makeWorld();
    makeScreen(world.document);
    world.document.readyState = 'complete';
    world.evaluate();

    await exerciseEveryControl(world);
});

await scenario('A second evaluation of the asset does not double-submit', async () => {
    const world = makeWorld();
    makeScreen(world.document);
    world.document.readyState = 'complete';
    world.evaluate();
    world.evaluate();

    control(world, 'guardian', 'refresh').click();
    await world.settle();

    check('one click is still exactly one request', world.requests.length === 1, `saw ${world.requests.length}`);
});

await scenario('DOMContentLoaded arriving twice does not double-submit', async () => {
    const world = makeWorld();
    world.document.readyState = 'loading';
    world.evaluate();
    makeScreen(world.document);
    world.document.fire('DOMContentLoaded', { type: 'DOMContentLoaded' });
    world.document.fire('DOMContentLoaded', { type: 'DOMContentLoaded' });

    control(world, 'guardian', 'refresh').click();
    await world.settle();

    check('one click is still exactly one request', world.requests.length === 1, `saw ${world.requests.length}`);
});

await scenario('Two products share the screen without colliding', async () => {
    const world = makeWorld();
    makeScreen(world.document, { slugs: ['brickie', 'guardian'] });
    world.document.readyState = 'complete';
    world.evaluate();

    control(world, 'brickie', 'refresh').click();
    await world.settle();

    check('only the pressed product is contacted', world.requests.length === 1, `saw ${world.requests.length}`);
    check('and on its own route', world.requests[0]?.url === '/typo3/ajax/brickie/refresh?token=t', world.requests[0]?.url);
    check('the other product is untouched', message(world, 'guardian') === '');
});

await scenario('A missing endpoint island disables the controls and says so', async () => {
    const world = makeWorld();
    makeScreen(world.document, { island: false });
    world.document.readyState = 'complete';
    world.evaluate();

    control(world, 'guardian', 'activate').click();
    await world.settle();

    check('no request is attempted', world.requests.length === 0, `saw ${world.requests.length}`);
    check('the control is disabled', control(world, 'guardian', 'activate').disabled === true);
    check('the failure is stated rather than silent', message(world, 'guardian') !== '');
});

await scenario('A section with no route for an action fails visibly', async () => {
    const world = makeWorld();
    makeScreen(world.document, { wiring: [{ slug: 'guardian', actions: { activate: '/typo3/ajax/guardian/activate?token=t' } }] });
    world.document.readyState = 'complete';
    world.evaluate();

    control(world, 'guardian', 'clear').click();
    await world.settle();

    check('no request is attempted', world.requests.length === 0, `saw ${world.requests.length}`);
    check('the failure is stated rather than silent', message(world, 'guardian') !== '');
});

await scenario('An empty key is refused locally', async () => {
    const world = makeWorld();
    makeScreen(world.document);
    world.document.readyState = 'complete';
    world.evaluate();

    control(world, 'guardian', 'activate').click();
    await world.settle();

    check('nothing is sent', world.requests.length === 0, `saw ${world.requests.length}`);
    check('the administrator is told why', message(world, 'guardian') !== '');
});

await scenario('Declining the removal confirmation sends nothing', async () => {
    const world = makeWorld();
    makeScreen(world.document);
    world.document.readyState = 'complete';
    world.evaluate();
    world.setConfirm(false);

    control(world, 'guardian', 'clear').click();
    await world.settle();

    check('nothing is sent', world.requests.length === 0, `saw ${world.requests.length}`);
});

await scenario('A refused result is shown generically and re-enables the controls', async () => {
    const world = makeWorld();
    makeScreen(world.document);
    world.document.readyState = 'complete';
    world.evaluate();
    world.setAnswer({ success: false, error: 'License verification failed.' });

    control(world, 'guardian', 'refresh').click();
    await world.settle();

    check('the failure is reported', message(world, 'guardian') === 'License verification failed.', message(world, 'guardian'));
    check('the control is usable again', control(world, 'guardian', 'refresh').disabled === false);
    check('no reload is scheduled', world.scheduled.length === 0);
});

await scenario('A transport failure is caught and reported', async () => {
    const world = makeWorld();
    makeScreen(world.document);
    world.document.readyState = 'complete';
    world.evaluate();
    world.setFailNext(true);

    control(world, 'guardian', 'refresh').click();
    await world.settle();

    check('the failure is reported', message(world, 'guardian') !== '');
    check('the control is usable again', control(world, 'guardian', 'refresh').disabled === false);
});

await scenario('A second click while a request is in flight is ignored', async () => {
    const world = makeWorld();
    makeScreen(world.document);
    world.document.readyState = 'complete';
    world.evaluate();

    const button = control(world, 'guardian', 'refresh');
    button.click();
    button.disabled = false;   // as if the browser had not yet applied the disable
    button.click();
    await world.settle();

    check('only one request was made', world.requests.length === 1, `saw ${world.requests.length}`);
});

/* ── Result ────────────────────────────────────────────────────────────────── */

console.log(`\n${passes} passed, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
