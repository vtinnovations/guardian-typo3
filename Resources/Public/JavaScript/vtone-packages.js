/*
 * Guardian for TYPO3 — shared V-T.ONE licence screen.
 *
 * One behaviour, applied to every section the server rendered. Nothing here knows
 * about a particular product: a section supplies its own endpoints, and this file
 * only moves an administrator's intent to them.
 *
 * There is exactly one renderer of licence state, and it is on the server. After
 * an operation the server has accepted, the page is reloaded so what is displayed
 * is what the server just persisted — never a second, client-side opinion of it
 * that could drift. Only transient messages ("verifying…", "the request failed")
 * are written here.
 *
 * The activation key travels from the input straight into the request body and is
 * cleared from the field afterwards. It is never written to the URL, to storage,
 * to a data attribute or to the console, and the server never sends one back —
 * the state it renders carries a masked preview only.
 *
 * Two properties of this file are load-bearing rather than stylistic:
 *
 *   - nothing looks at the document until the document is ready. TYPO3 renders a
 *     module's script into the head, so this file runs *before* the markup it
 *     binds to exists. A top-level element lookup followed by a return would
 *     therefore return permanently, leaving three visible buttons that do
 *     nothing at all and say nothing about it. Every lookup lives inside boot(),
 *     which runs on DOMContentLoaded when the document is still loading and
 *     immediately when it is not, so both orders reach the same bound state;
 *   - binding is idempotent and delegated. One listener on the container handles
 *     every section, so a second boot — a remount, a partial navigation, a second
 *     copy of the asset — cannot install a second handler and submit twice.
 *
 * A control that cannot be wired is disabled and says so. Silence is not an
 * option here: an administrator who cannot tell that Remove Licence is inert
 * believes a licence was withdrawn when it was not.
 */
(function () {
    'use strict';

    var ROOT_ID = 'vtonePackages';
    var CONFIG_ID = 'vtone-packages-config';
    var BOUND_ATTRIBUTE = 'data-vtone-bound';

    /** Backend labels, with an English fallback if one is ever missing. */
    function t(key, fallback) {
        var lang = window.TYPO3 && window.TYPO3.lang;
        var value = lang && lang[key];
        return typeof value === 'string' && value !== '' ? value : fallback;
    }

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body ? JSON.stringify(body) : '{}'
        }).then(function (response) {
            return response.text().then(function (text) {
                try {
                    return text ? JSON.parse(text) : {};
                } catch (e) {
                    return { success: false, error: t('js.license.checkFailed', 'License verification failed.') };
                }
            });
        });
    }

    /**
     * The endpoints the server published for each section, by slug. An unreadable
     * or absent island yields an empty map, which is reported rather than assumed
     * away.
     */
    function readWiring(island) {
        var wiring = {};
        if (!island) {
            return null;
        }
        var config;
        try {
            config = JSON.parse(island.textContent || '{}');
        } catch (e) {
            return null;
        }
        var sections = (config && config.sections) || [];
        for (var i = 0; i < sections.length; i++) {
            var section = sections[i];
            if (section && typeof section.slug === 'string' && section.slug !== '') {
                wiring[section.slug] = section.actions || {};
            }
        }
        return wiring;
    }

    function boot() {
        var root = document.getElementById(ROOT_ID);
        if (!root || root.getAttribute(BOUND_ATTRIBUTE) === '1') {
            return;
        }
        // Claimed before any listener is added: a second boot from any source
        // finds the screen already taken and does nothing.
        root.setAttribute(BOUND_ATTRIBUTE, '1');

        var wiring = readWiring(document.getElementById(CONFIG_ID));
        var cards = root.querySelectorAll('.vtone-package');

        if (wiring === null) {
            // The screen rendered controls but the server's endpoint island did
            // not survive. Saying so is the whole point: an inert button that
            // looks live is worse than a disabled one.
            for (var i = 0; i < cards.length; i++) {
                disable(cards[i], t('js.license.endpointMissing', 'License service unavailable — reload the module and try again.'));
            }
            return;
        }

        // One listener for the whole screen. Which section and which operation a
        // click belongs to is answered from the DOM at the time of the click, so
        // markup that arrives later is handled by the same binding.
        root.addEventListener('click', function (event) {
            var control = closest(event.target, '[data-role]');
            if (!control) {
                return;
            }
            var role = control.getAttribute('data-role');
            if (role !== 'activate' && role !== 'refresh' && role !== 'clear') {
                return;
            }
            var card = closest(control, '.vtone-package');
            if (!card) {
                return;
            }
            event.preventDefault();
            run(card, role, wiring[card.getAttribute('data-package')] || {});
        });
    }

    function closest(node, selector) {
        // Element.closest is available in every browser TYPO3 13/14 supports; the
        // guard is for a click that started on a text node.
        while (node && typeof node.closest !== 'function') {
            node = node.parentNode;
        }
        return node ? node.closest(selector) : null;
    }

    function controls(card) {
        return card.querySelectorAll('[data-role="activate"], [data-role="refresh"], [data-role="clear"]');
    }

    function say(card, text) {
        var message = card.querySelector('[data-role="message"]');
        if (message) {
            message.textContent = text || '';
        }
    }

    function busy(card, isBusy, text) {
        var buttons = controls(card);
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].disabled = isBusy;
        }
        say(card, text);
    }

    function disable(card, text) {
        var buttons = controls(card);
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].disabled = true;
        }
        say(card, text);
    }

    /**
     * Runs one operation for one section. A result the server accepted is
     * followed by a reload, so the state on screen is the state that was stored;
     * anything else leaves the page as it is and says why.
     */
    function run(card, role, actions) {
        if (card.getAttribute('data-vtone-busy') === '1') {
            return; // an operation is already in flight for this section
        }

        var url = actions[role];
        if (!url) {
            disable(card, t('js.license.endpointMissing', 'License service unavailable — reload the module and try again.'));
            return;
        }

        var input = card.querySelector('[data-role="key"]');
        var body = {};
        var running = t('js.license.checking', 'Verifying…');

        if (role === 'activate') {
            var key = input ? String(input.value).trim() : '';
            if (key === '') {
                // Activating is for a key that was entered; re-confirming the
                // stored one is what Update Licence is for.
                say(card, t('js.license.enterKey', 'Please enter a license key.'));
                return;
            }
            body = { key: key };
        } else if (role === 'clear') {
            if (!window.confirm(t('js.license.removeConfirm', 'Really remove the stored Guardian license?'))) {
                return;
            }
            running = t('js.license.removing', 'Removing license…');
        }
        // A refresh submits no key at all: the stored one is used server-side.

        card.setAttribute('data-vtone-busy', '1');
        busy(card, true, running);

        post(url, body).then(function (data) {
            if (input) {
                input.value = '';
            }
            if (data && data.success === true) {
                say(card, data.valid === true
                    ? t('js.license.activated', 'License verified and activated.')
                    : (data.message || t('js.license.invalidResult', 'License key could not be validated.')));
                // Deliberately left busy: the page is about to be replaced by
                // the server's own rendering of what was just stored.
                window.setTimeout(function () { window.location.reload(); }, 900);
                return;
            }
            card.removeAttribute('data-vtone-busy');
            busy(card, false, (data && data.error) || t('js.license.checkFailed', 'License verification failed.'));
        }).catch(function () {
            card.removeAttribute('data-vtone-busy');
            busy(card, false, t('js.license.checkFailed', 'License verification failed.'));
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
}());
