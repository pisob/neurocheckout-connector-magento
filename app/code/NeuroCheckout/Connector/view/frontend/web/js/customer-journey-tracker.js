(function () {
    'use strict';

    var config = window.NCMagentoCustomerJourney || {};
    if (!config.endpoint || !config.token) {
        return;
    }

    var eventPrefix = String(config.eventPrefix || 'magento.customer_journey.');
    var maxEventsPerPage = Math.max(1, Number(config.maxEventsPerPage || 18));
    var slowPageMs = Math.max(1000, Number(config.slowPageMs || 5000));
    var startedAt = Date.now();
    var sentCount = 0;
    var exitSent = false;
    var dedupe = {};
    var queueKey = 'ncmagento_journey_queue_v1';
    var visitorCookie = 'ncmagento_journey_visitor';
    var sessionCookie = 'ncmagento_journey_session';

    function uuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            var v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    function nowIso() {
        return new Date().toISOString();
    }

    function safeText(value, maxLength) {
        var text = String(value || '').trim();
        if (!text) {
            return '';
        }

        text = text.replace(/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/g, '[email]');
        text = text.replace(/https?:\/\/[^\s"'<>]+/gi, '[url]');
        text = text.replace(/\b(?:sk|pk|rk|whsec|secret|token|api[_-]?key)[_-]?[A-Za-z0-9]{12,}\b/gi, '[secret]');

        return text.slice(0, maxLength || 240);
    }

    function safeNumber(value, fallback) {
        var number = Number(value);
        return Number.isFinite ? (Number.isFinite(number) ? number : fallback) : (isFinite(number) ? number : fallback);
    }

    function referrerHost() {
        try {
            return document.referrer ? (new URL(document.referrer)).hostname : '';
        } catch (e) {
            return '';
        }
    }

    function getCookie(name) {
        var prefix = name + '=';
        var parts = String(document.cookie || '').split(';');
        for (var i = 0; i < parts.length; i += 1) {
            var part = parts[i].trim();
            if (part.indexOf(prefix) === 0) {
                return decodeURIComponent(part.slice(prefix.length));
            }
        }
        return '';
    }

    function setCookie(name, value, maxAgeSeconds) {
        var secure = window.location && window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = name + '=' + encodeURIComponent(value) + '; Path=/; Max-Age=' + maxAgeSeconds + '; SameSite=Lax' + secure;
    }

    function ensureId(name, prefix, maxAgeSeconds) {
        var value = getCookie(name);
        if (!/^[A-Za-z0-9_.:-]{12,80}$/.test(value)) {
            value = prefix + '_' + uuid();
            setCookie(name, value, maxAgeSeconds);
        }
        return value;
    }

    var visitorId = ensureId(visitorCookie, 'mgv', 60 * 60 * 24 * 365);
    var sessionId = ensureId(sessionCookie, 'mgs', 60 * 30);

    function storageGetQueue() {
        try {
            var parsed = JSON.parse(window.localStorage.getItem(queueKey) || '[]');
            return Array.isArray(parsed) ? parsed.slice(-80) : [];
        } catch (e) {
            return [];
        }
    }

    function storageSetQueue(items) {
        try {
            window.localStorage.setItem(queueKey, JSON.stringify(items.slice(-80)));
        } catch (e) {
        }
    }

    function enqueue(payload) {
        var items = storageGetQueue();
        items.push(payload);
        storageSetQueue(items);
    }

    function pageContext(extra) {
        var base = config.context || {};
        var result = {};
        var key;

        for (key in base) {
            if (Object.prototype.hasOwnProperty.call(base, key)) {
                result[key] = base[key];
            }
        }
        for (key in (extra || {})) {
            if (Object.prototype.hasOwnProperty.call(extra, key)) {
                result[key] = extra[key];
            }
        }

        result.module_version = safeText(config.moduleVersion || '', 40);
        result.store_id = config.storeId || null;
        result.cart_id = config.cartId || null;
        result.page_path = safeText(window.location ? window.location.pathname : '', 200);
        result.referrer_host = safeText(referrerHost(), 120);

        return result;
    }

    function journeyContext() {
        return {
            visitor_id: visitorId,
            session_id: sessionId,
            cart_id: config.cartId || null
        };
    }

    function productFromDom() {
        var product = {};
        var form = document.querySelector('form#product_addtocart_form, form[data-product-sku]');
        var idInput = form ? form.querySelector('input[name="product"]') : null;
        var skuNode = document.querySelector('[itemprop="sku"], [data-product-sku]');
        var nameNode = document.querySelector('h1.page-title span, h1.page-title, [data-ui-id="page-title-wrapper"]');

        if (idInput && idInput.value) {
            product.id = safeText(idInput.value, 80);
        }
        if (skuNode) {
            product.sku = safeText(skuNode.getAttribute('content') || skuNode.getAttribute('data-product-sku') || skuNode.textContent, 120);
        }
        if (nameNode) {
            product.name = safeText(nameNode.textContent, 180);
        }

        return product;
    }

    function sendPayload(payload, allowBeacon) {
        var body = JSON.stringify(payload);
        if (allowBeacon && navigator.sendBeacon) {
            try {
                if (navigator.sendBeacon(config.endpoint, new Blob([body], { type: 'application/json' }))) {
                    return Promise.resolve(true);
                }
            } catch (e) {
            }
        }

        try {
            return window.fetch(config.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Neuro-Journey-Token': config.token
                },
                body: body,
                credentials: 'same-origin',
                keepalive: true
            }).then(function (response) {
                return response && response.status >= 200 && response.status < 300;
            }).catch(function () {
                return false;
            });
        } catch (e) {
            return Promise.resolve(false);
        }
    }

    function sendEvent(suffix, details, extraContext, options) {
        if (sentCount >= maxEventsPerPage) {
            return;
        }

        options = options || {};
        details = details || {};
        var dedupeKey = suffix + ':' + JSON.stringify(details).slice(0, 300) + ':' + JSON.stringify(extraContext || {}).slice(0, 300);
        if (dedupe[dedupeKey]) {
            return;
        }
        dedupe[dedupeKey] = true;
        sentCount += 1;

        var payload = {
            token: config.token,
            store_id: config.storeId || null,
            event_id: uuid(),
            event_type: eventPrefix + suffix,
            occurred_at: nowIso(),
            source: {
                platform: 'magento'
            },
            journey: journeyContext(),
            page: {
                title: safeText(document.title || '', 180),
                path: safeText(window.location ? window.location.pathname : '', 200),
                type: safeText((config.context || {}).page_type || '', 80)
            },
            event: details,
            context: pageContext(extraContext),
            metrics: options.metrics || {},
            privacy: {
                contains_raw_server_logs: false,
                contains_payment_provider_logs: false,
                contains_form_values: false,
                contains_payment_data: false,
                contains_cookie_values: false,
                contains_session_ids: false
            }
        };

        sendPayload(payload, !!options.beacon).then(function (ok) {
            if (!ok) {
                enqueue(payload);
            }
        });
    }

    function flushQueue() {
        var items = storageGetQueue();
        if (!items.length) {
            return;
        }

        var keep = [];
        var chain = Promise.resolve();
        items.slice(0, 12).forEach(function (payload) {
            chain = chain.then(function () {
                return sendPayload(payload, false).then(function (ok) {
                    if (!ok) {
                        keep.push(payload);
                    }
                });
            });
        });

        chain.then(function () {
            storageSetQueue(keep.concat(items.slice(12)));
        });
    }

    function detectInitialPage() {
        var ctx = config.context || {};
        sendEvent('page_view', { name: 'page_view' }, {}, { beacon: false });

        if (ctx.page_type === 'product') {
            sendEvent('product_view', {
                name: 'product_view',
                product: ctx.product || productFromDom()
            });
        } else if (ctx.page_type === 'category') {
            sendEvent('category_view', {
                name: 'category_view',
                category: ctx.category || null
            });
        } else if (ctx.page_type === 'cart') {
            sendEvent('cart_view', { name: 'cart_view' });
        } else if (ctx.page_type === 'checkout') {
            sendEvent('checkout_started', { name: 'checkout_started' });
        }
    }

    function bindAddToCartIntent() {
        document.addEventListener('click', function (event) {
            var target = event.target;
            if (!target || !target.closest) {
                return;
            }

            var button = target.closest('button.tocart, button.action.tocart, #product-addtocart-button, [data-role="tocart"], .action.tocart, form#product_addtocart_form button[type="submit"], form[data-product-sku] button[type="submit"]');
            if (!button) {
                return;
            }

            sendEvent('add_to_cart_intent', {
                name: 'add_to_cart_intent',
                product: (config.context && config.context.product) || productFromDom(),
                button_label: safeText(button.textContent, 80)
            });
        }, true);
    }

    function bindCheckoutSignals() {
        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || !form.matches) {
                return;
            }

            if (form.matches('form#co-shipping-form, form[data-role="checkout"], form[action*="checkout"], form[action*="payment"]')) {
                sendEvent('checkout_step', {
                    name: 'checkout_step',
                    form_role: safeText(form.getAttribute('id') || form.getAttribute('name') || form.getAttribute('data-role') || 'checkout_form', 100)
                });
            }
        }, true);

        function collectVisibleErrors() {
            var nodes = document.querySelectorAll('.mage-error, .field-error, .message-error, .messages .error, .message.error, .checkout-error');
            if (!nodes.length) {
                return;
            }

            var messages = [];
            Array.prototype.slice.call(nodes, 0, 6).forEach(function (node) {
                var text = safeText(node.textContent, 160);
                if (text) {
                    messages.push(text);
                }
            });

            if (messages.length) {
                sendEvent('form_error', {
                    name: 'form_error',
                    visible_errors: messages
                }, {}, { metrics: { visible_error_count: messages.length } });
            }
        }

        if (window.MutationObserver) {
            var observer = new MutationObserver(function () {
                collectVisibleErrors();
            });
            observer.observe(document.documentElement, { childList: true, subtree: true });
        }
        window.setTimeout(collectVisibleErrors, 1200);
    }

    function bindPerformanceSignals() {
        window.addEventListener('load', function () {
            window.setTimeout(function () {
                var nav = performance && performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
                var duration = nav && nav.duration ? nav.duration : Date.now() - startedAt;
                if (duration >= slowPageMs) {
                    sendEvent('performance', {
                        name: 'slow_page',
                        page_type: safeText((config.context || {}).page_type || '', 80)
                    }, {}, { metrics: { page_load_ms: Math.round(safeNumber(duration, 0)) } });
                }
            }, 0);
        });
    }

    function bindExitIntent() {
        function sendExit(reason) {
            if (exitSent) {
                return;
            }
            exitSent = true;
            sendEvent('exit_intent', {
                name: 'exit_intent',
                reason: safeText(reason, 40)
            }, {}, { beacon: true, metrics: { dwell_ms: Date.now() - startedAt } });
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden' && Date.now() - startedAt > 8000) {
                sendExit('visibility_hidden');
            }
        });
        window.addEventListener('pagehide', function () {
            if (Date.now() - startedAt > 8000) {
                sendExit('pagehide');
            }
        });
    }

    flushQueue();
    detectInitialPage();
    bindAddToCartIntent();
    bindCheckoutSignals();
    bindPerformanceSignals();
    bindExitIntent();
}());
