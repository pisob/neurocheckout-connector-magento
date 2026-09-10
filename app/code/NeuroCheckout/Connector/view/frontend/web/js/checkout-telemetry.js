(function () {
    'use strict';

    var config = window.ncCheckoutTelemetry || {};
    if (!config.endpoint || !config.token) {
        return;
    }

    var startedAt = Date.now();
    var sentCount = 0;
    var issueCount = 0;
    var maxEventsPerPage = Number(config.maxEventsPerPage || 12);
    var maxIssueEventsPerPage = Number(config.maxIssueEventsPerPage || 8);
    var slowRequestMs = Number(config.slowRequestMs || 5000);
    var slowCheckoutMs = Number(config.slowCheckoutMs || 5000);
    var dedupe = {};

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

    function isFiniteNumber(value) {
        if (Number && typeof Number.isFinite === 'function') {
            return Number.isFinite(value);
        }

        return typeof value === 'number' && isFinite(value);
    }

    function safeNumber(value, fallback) {
        var number = Number(value);
        return isFiniteNumber(number) ? number : fallback;
    }

    function scrubText(value, maxLength) {
        var text = String(value || '').trim();
        if (!text) {
            return '';
        }

        text = text.replace(/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/g, '[email]');
        text = text.replace(/https?:\/\/[^\s"'<>]+/gi, '[url]');
        text = text.replace(/\b(?:sk|pk|rk|whsec|secret|token|api[_-]?key)[_-]?[A-Za-z0-9]{12,}\b/gi, '[secret]');
        return text.slice(0, maxLength || 240);
    }

    function mergeContext(base, extra) {
        var result = {};
        var key;

        base = base || {};
        extra = extra || {};

        for (key in base) {
            if (Object.prototype.hasOwnProperty.call(base, key)) {
                result[key] = base[key];
            }
        }
        for (key in extra) {
            if (Object.prototype.hasOwnProperty.call(extra, key)) {
                result[key] = extra[key];
            }
        }

        return result;
    }

    function isCheckoutUrl(url) {
        var text = String(url || '').toLowerCase();
        if (!text) {
            return false;
        }

        return text.indexOf('checkout') !== -1
            || text.indexOf('cart') !== -1
            || text.indexOf('onestepcheckout') !== -1
            || text.indexOf('/rest/') !== -1 && text.indexOf('/carts/') !== -1
            || text.indexOf('/customer/section/load') !== -1;
    }

    function searchKeys() {
        if (!window.location || !window.location.search) {
            return '';
        }

        try {
            var params = new URLSearchParams(window.location.search);
            var keys = [];
            params.forEach(function (_value, key) {
                keys.push(key);
            });
            return keys.slice(0, 12).join(',');
        } catch (e) {
            return '';
        }
    }

    function detectBrowserFamily() {
        var ua = String(navigator.userAgent || '').toLowerCase();
        if (ua.indexOf('edg/') !== -1) return 'edge';
        if (ua.indexOf('chrome/') !== -1) return 'chrome';
        if (ua.indexOf('firefox/') !== -1) return 'firefox';
        if (ua.indexOf('safari/') !== -1) return 'safari';
        return 'unknown';
    }

    function storageWorks(name) {
        try {
            var storage = window[name];
            if (!storage) {
                return false;
            }
            var key = 'nc_telemetry_test';
            storage.setItem(key, '1');
            storage.removeItem(key);
            return true;
        } catch (e) {
            return false;
        }
    }

    function baseContext() {
        return {
            page_path: window.location ? String(window.location.pathname || '').slice(0, 200) : '',
            page_search_keys: searchKeys(),
            module_version: scrubText(config.moduleVersion || '', 40),
            store_id: config.storeId || null,
            cart_id: config.cartId || null,
            user_agent_family: detectBrowserFamily(),
            viewport_width: window.innerWidth || null,
            viewport_height: window.innerHeight || null,
            cookies_enabled: !!navigator.cookieEnabled,
            local_storage_available: storageWorks('localStorage'),
            session_storage_available: storageWorks('sessionStorage')
        };
    }

    function sendEvent(eventType, metrics, context, isIssue) {
        if (sentCount >= maxEventsPerPage) {
            return;
        }
        if (isIssue && issueCount >= maxIssueEventsPerPage) {
            return;
        }

        var dedupeKey = eventType + ':' + JSON.stringify(metrics || {}) + ':' + JSON.stringify(context || {});
        dedupeKey = dedupeKey.slice(0, 500);
        if (dedupe[dedupeKey]) {
            return;
        }
        dedupe[dedupeKey] = true;

        sentCount += 1;
        if (isIssue) {
            issueCount += 1;
        }

        var payload = {
            token: config.token,
            event_id: uuid(),
            event_type: eventType,
            occurred_at: nowIso(),
            source: {
                platform: 'magento'
            },
            metrics: metrics || {},
            context: mergeContext(baseContext(), context || {}),
            privacy: {
                contains_raw_server_logs: false,
                contains_payment_provider_logs: false,
                contains_form_values: false,
                contains_personal_data: false
            }
        };

        var body = JSON.stringify(payload);

        try {
            if (navigator.sendBeacon) {
                var blob = new Blob([body], { type: 'application/json' });
                if (navigator.sendBeacon(config.endpoint, blob)) {
                    return;
                }
            }
        } catch (e) {
        }

        try {
            window.fetch(config.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Neuro-Telemetry-Token': config.token
                },
                body: body,
                credentials: 'same-origin',
                keepalive: true
            }).catch(function () {});
        } catch (e) {
        }
    }

    function collectFrictionSnapshot() {
        var requiredFields = document.querySelectorAll('input[required], select[required], textarea[required], .required-entry, ._required');
        var paymentMethods = document.querySelectorAll('input[name="payment[method]"], .payment-method, [data-bind*="payment"]');
        var shippingMethods = document.querySelectorAll('input[name^="ko_unique_"], input[type="radio"][name*="shipping"], .table-checkout-shipping-method input[type="radio"]');
        var checkoutButtons = document.querySelectorAll('button.checkout, button.action.primary.checkout, [data-role="proceed-to-checkout"], .checkout-payment-method button.action.primary');
        var errorNodes = document.querySelectorAll('.mage-error, .field-error, .message-error, .messages .error, .message.error');

        sendEvent('magento.checkout.friction_snapshot', {
            required_field_count: requiredFields.length,
            payment_option_count: paymentMethods.length,
            shipping_option_count: shippingMethods.length,
            checkout_button_count: checkoutButtons.length,
            visible_error_count: errorNodes.length
        }, {
            checkout_step_hint: inferCheckoutStep()
        }, false);
    }

    function inferCheckoutStep() {
        var path = window.location ? String(window.location.pathname || '').toLowerCase() : '';
        if (path.indexOf('cart') !== -1) return 'cart';
        if (document.querySelector('#checkout-step-shipping, .checkout-shipping-address')) return 'shipping';
        if (document.querySelector('#checkout-payment-method-load, .payment-methods')) return 'payment';
        if (path.indexOf('checkout') !== -1) return 'checkout';
        return 'unknown';
    }

    function collectPerformance() {
        var duration = Date.now() - startedAt;
        var navigationDuration = 0;

        try {
            var entries = performance && performance.getEntriesByType ? performance.getEntriesByType('navigation') : [];
            if (entries && entries[0]) {
                navigationDuration = safeNumber(entries[0].duration, 0);
            } else if (performance && performance.timing) {
                navigationDuration = performance.timing.loadEventEnd - performance.timing.navigationStart;
            }
        } catch (e) {
        }

        navigationDuration = safeNumber(navigationDuration, 0);
        if (duration >= slowCheckoutMs || navigationDuration >= slowCheckoutMs) {
            sendEvent('magento.checkout.performance', {
                time_on_page_ms: duration,
                navigation_duration_ms: Math.round(navigationDuration)
            }, {
                checkout_step_hint: inferCheckoutStep()
            }, true);
        }
    }

    function installErrorHandlers() {
        window.addEventListener('error', function (event) {
            sendEvent('magento.checkout.js_error', {
                error_count: 1
            }, {
                message: scrubText(event && event.message ? event.message : 'javascript_error', 240),
                filename: scrubText(event && event.filename ? event.filename : '', 200),
                line: event && event.lineno ? Number(event.lineno) : null
            }, true);
        }, true);

        window.addEventListener('unhandledrejection', function (event) {
            var reason = event && event.reason ? event.reason : 'unhandled_rejection';
            var message = typeof reason === 'string' ? reason : (reason && reason.message ? reason.message : 'unhandled_rejection');
            sendEvent('magento.checkout.js_error', {
                rejection_count: 1
            }, {
                message: scrubText(message, 240),
                kind: 'unhandled_rejection'
            }, true);
        });
    }

    function installFetchObserver() {
        if (!window.fetch) {
            return;
        }

        var originalFetch = window.fetch;
        window.fetch = function () {
            var args = arguments;
            var url = args && args[0] && args[0].url ? args[0].url : args[0];
            var started = Date.now();

            return originalFetch.apply(this, args).then(function (response) {
                observeRequest(url, Date.now() - started, response && response.status ? response.status : 0);
                return response;
            }).catch(function (error) {
                observeRequest(url, Date.now() - started, 0, error);
                throw error;
            });
        };
    }

    function installXhrObserver() {
        if (!window.XMLHttpRequest) {
            return;
        }

        var originalOpen = window.XMLHttpRequest.prototype.open;
        var originalSend = window.XMLHttpRequest.prototype.send;

        window.XMLHttpRequest.prototype.open = function (method, url) {
            this.__ncTelemetryUrl = url;
            return originalOpen.apply(this, arguments);
        };

        window.XMLHttpRequest.prototype.send = function () {
            var xhr = this;
            var started = Date.now();

            xhr.addEventListener('loadend', function () {
                observeRequest(xhr.__ncTelemetryUrl || '', Date.now() - started, xhr.status || 0);
            });

            return originalSend.apply(this, arguments);
        };
    }

    function observeRequest(url, durationMs, status, error) {
        if (!isCheckoutUrl(url)) {
            return;
        }

        durationMs = safeNumber(durationMs, 0);
        status = Number(status || 0);
        if (durationMs < slowRequestMs && status < 400 && !error) {
            return;
        }

        sendEvent('magento.checkout.request_anomaly', {
            duration_ms: Math.round(durationMs),
            http_status: status
        }, {
            request_path: scrubText(String(url || '').replace(window.location.origin || '', ''), 200),
            error_message: error ? scrubText(error.message || String(error), 200) : ''
        }, true);
    }

    installErrorHandlers();
    installFetchObserver();
    installXhrObserver();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', collectFrictionSnapshot);
    } else {
        collectFrictionSnapshot();
    }

    window.addEventListener('load', function () {
        setTimeout(collectPerformance, 0);
    });
}());
