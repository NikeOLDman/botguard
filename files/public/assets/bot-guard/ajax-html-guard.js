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
        if (!url) {
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
        // Price-only filters and clearing the last selection use the base URL.
        var headers = init && init.headers ? init.headers : input && input.headers;
        var catalog = headers && (typeof headers.get === 'function'
            ? headers.get('X-Bot-Guard-Catalog')
            : headers['X-Bot-Guard-Catalog']);
        return catalog === '1' || url.indexOf(pathNeedle) !== -1;
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
            return false;
        }

        // Catalog HTML contains these marker names in window.__bgAjaxHtmlGuard.
        // Match rendered elements, not strings inside the configuration script.
        var match;
        var classes = /<[a-z][^>]*\sclass\s*=\s*["']([^"']*)["']/gi;
        while ((match = classes.exec(html))) {
            if (match[1].split(/\s+/).indexOf(challengeMarker) !== -1) return true;
        }
        var fields = /<input\b[^>]*\sname\s*=\s*["']([^"']*)["']/gi;
        while ((match = fields.exec(html))) {
            if (match[1] === captchaField) return true;
        }

        return false;
    }

    function triggerReload(url) {
        var key = 'bot_guard.last_challenge_navigation';
        try {
            var previous = JSON.parse(window.sessionStorage.getItem(key) || 'null');
            if (previous && previous.url === url && Date.now() - previous.time < 30000) {
                throw new Error('Проверка не завершена. Обновите страницу вручную.');
            }
            window.sessionStorage.setItem(key, JSON.stringify({url:url, time:Date.now()}));
        } catch (error) {
            if (error.message === 'Проверка не завершена. Обновите страницу вручную.') throw error;
        }
        // Preserve the requested filter and campaign parameters through verification.
        window.location.assign(url);
        var error = new Error('Выполняется проверка браузера.');
        error.name = 'BotGuardNavigation';
        throw error;
    }

    window.fetch = function (input, init) {
        var url = resolveUrl(input);
        if (!shouldInspect(url, input, init)) {
            return nativeFetch(input, init);
        }

        return nativeFetch(input, init).then(function (response) {
            if (!response.ok) {
                var seconds = parseInt(response.headers.get('Retry-After') || '60', 10);
                var message = response.status === 429
                    ? 'Слишком много запросов. Повторите через ' + (isFinite(seconds) ? seconds : 60) + ' сек.'
                    : 'Не удалось обновить каталог. Повторите запрос позже.';
                var error = new Error(message);
                error.status = response.status;
                error.retryAfter = seconds;
                throw error;
            }
            if (headerLooksLikeChallenge(response)) {
                triggerReload(url);
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
                        triggerReload(url);
                    }
                    return response;
                });
        });
    };
})(window);
