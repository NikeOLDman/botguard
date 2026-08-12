/**
 * BotGuard AJAX HTML guard.
 *
 * Protects catalog AJAX filters (fetch → HTML) from inserting BotGuard
 * challenge / error pages that have no catalogue layout (.l-main).
 * Lives entirely under /assets/bot-guard — no site frontend patches required.
 */
(function (window) {
    'use strict';

    if (window.__bgAjaxHtmlGuardInstalled) {
        return;
    }
    window.__bgAjaxHtmlGuardInstalled = true;

    if (typeof window.fetch !== 'function') {
        return;
    }

    var config = window.__bgAjaxHtmlGuard || {};
    var pathNeedle = typeof config.pathNeedle === 'string' ? config.pathNeedle : '/filtered';
    var mainMarker = typeof config.mainMarker === 'string' ? config.mainMarker : 'l-main';
    var challengeMarker = typeof config.challengeMarker === 'string' ? config.challengeMarker : 'bg-challenge';
    var captchaField = typeof config.captchaField === 'string' ? config.captchaField : '_bgct';
    var headerName = typeof config.headerName === 'string' ? config.headerName : 'X-Bot-Guard';

    var nativeFetch = window.fetch.bind(window);

    function resolveUrl(input) {
        if (typeof input === 'string') {
            return input;
        }
        if (input && typeof input.url === 'string') {
            return input.url;
        }
        try {
            return String(input);
        } catch (e) {
            return '';
        }
    }

    function isSameOrigin(url) {
        if (!url || url.charAt(0) === '/' || url.charAt(0) === '?') {
            return true;
        }
        try {
            var resolved = new URL(url, window.location.href);
            return resolved.origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    function getMethod(input, init) {
        if (init && init.method) {
            return String(init.method).toUpperCase();
        }
        if (input && typeof input.method === 'string') {
            return String(input.method).toUpperCase();
        }
        return 'GET';
    }

    function shouldInspect(url, input, init) {
        if (!isSameOrigin(url)) {
            return false;
        }
        if (getMethod(input, init) !== 'GET') {
            return false;
        }
        return url.indexOf(pathNeedle) !== -1;
    }

    function headerLooksLikeChallenge(response) {
        try {
            var value = response.headers.get(headerName);
            if (!value) {
                return false;
            }
            value = String(value).toLowerCase();
            return value.indexOf('challenge') !== -1 || value.indexOf('reload') !== -1;
        } catch (e) {
            return false;
        }
    }

    function bodyLooksLikeChallenge(html) {
        if (!html || typeof html !== 'string') {
            return true;
        }

        if (html.indexOf(challengeMarker) !== -1) {
            return true;
        }

        if (html.indexOf(captchaField) !== -1) {
            return true;
        }

        var normalized = html.toLowerCase();
        var looksLikeDocument =
            normalized.indexOf('<!doctype') !== -1 ||
            normalized.indexOf('<html') !== -1;

        if (!looksLikeDocument) {
            return false;
        }

        // Full HTML page without catalogue root — challenge / deny / error shell.
        return normalized.indexOf(mainMarker) === -1;
    }

    function triggerReload() {
        try {
            window.location.reload();
        } catch (e) {
            window.location.href = window.location.href;
        }
    }

    function hangForever() {
        return new Promise(function () {});
    }

    window.fetch = function (input, init) {
        var url = resolveUrl(input);
        if (!shouldInspect(url, input, init)) {
            return nativeFetch(input, init);
        }

        return nativeFetch(input, init).then(function (response) {
            if (headerLooksLikeChallenge(response)) {
                triggerReload();
                return hangForever();
            }

            var contentType = '';
            try {
                contentType = String(response.headers.get('Content-Type') || '').toLowerCase();
            } catch (e) {
                contentType = '';
            }

            if (contentType && contentType.indexOf('text/html') === -1 && contentType.indexOf('text/plain') === -1) {
                return response;
            }

            return response
                .clone()
                .text()
                .then(function (body) {
                    if (bodyLooksLikeChallenge(body)) {
                        triggerReload();
                        return hangForever();
                    }
                    return response;
                })
                .catch(function () {
                    return response;
                });
        });
    };
})(window);
