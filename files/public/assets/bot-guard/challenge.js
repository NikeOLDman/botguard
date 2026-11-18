(function () {
    'use strict';
    var config = window.__bgChallenge;
    if (!config) return;
    var page = document.getElementById('bg-page'), status = document.getElementById('bg-status');
    var detail = document.getElementById('bg-detail'), retry = document.getElementById('bg-retry');
    var steps = ['bg-step-browser', 'bg-step-access', 'bg-step-ready'];
    var started = Date.now(), phase = 'checking', finished = false, proofReady = false, fallbackStarted = false;
    var worker = null, workerTimer = null, requestTimer = null, delayTimer = null, controller = null;
    var slowTimer = setTimeout(function () {
        if (finished || !detail) return;
        detail.textContent = phase === 'checking'
            ? 'Этому устройству нужно чуть больше времени. Проверка продолжается.'
            : 'Ждём ответ сайта. Иногда это занимает немного больше времени.';
    }, 6500);

    function setState(state, message, explanation) {
        var previousPhase = phase;
        phase = state;
        if (page) page.setAttribute('data-state', state);
        if (status) status.textContent = message;
        if (detail) detail.textContent = explanation;
        var active = state === 'success' ? 2 : state === 'confirming' || state === 'error' && previousPhase === 'confirming' ? 1 : 0;
        for (var i = 0; i < steps.length; i++) {
            var step = document.getElementById(steps[i]);
            if (!step) continue;
            step.className = i < active ? 'is-done' : i === active ? 'is-active' : '';
            if (i === active) step.setAttribute('aria-current', 'step');
            else step.removeAttribute('aria-current');
        }
    }
    function cleanup() {
        clearTimeout(slowTimer); clearTimeout(workerTimer); clearTimeout(requestTimer); clearTimeout(delayTimer);
        if (worker) { worker.terminate(); worker = null; }
    }
    function fail(message, explanation) {
        if (finished) return;
        finished = true;
        cleanup();
        if (controller) controller.abort();
        setState('error', message, explanation || 'Нажмите кнопку ниже, чтобы попробовать ещё раз.');
        if (retry) retry.hidden = false;
    }
    if (retry) retry.onclick = function () { window.location.reload(); };

    function complete(error, solution) {
        if (finished || proofReady) return;
        if (error || !/^[0-9]{1,12}$/.test(String(solution))) {
            fail('Не удалось завершить проверку', 'Обновите проверку. Страница, на которую вы шли, сохранится.');
            return;
        }
        proofReady = true;
        clearTimeout(workerTimer);
        if (worker) { worker.terminate(); worker = null; }
        setState('confirming', 'Подтверждаем доступ', 'Браузер готов. Получаем подтверждение сайта.');
        delayTimer = setTimeout(function () {
            if (finished) return;
            var body = new URLSearchParams();
            body.set('nonce', config.nonce); body.set('solution', solution);
            var options = {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body.toString(), credentials:'same-origin'};
            if (typeof AbortController === 'function') {
                controller = new AbortController(); options.signal = controller.signal;
            }
            requestTimer = setTimeout(function () {
                fail('Сайт отвечает дольше обычного', 'Ответ не пришёл вовремя. Попробуйте повторить проверку.');
            }, 15000);
            fetch(config.verify, options)
                .then(function (response) {
                    if (finished) return null;
                    if (response.status === 429 || response.status === 503) {
                        var wait = response.headers.get('Retry-After') || '60';
                        fail('Небольшая пауза — сайт занят', /^[0-9]{1,5}$/.test(wait)
                            ? 'Повторите проверку через ' + wait + ' сек.'
                            : 'Подождите немного и повторите проверку.');
                        return null;
                    }
                    if (!response.ok) throw new Error('verification');
                    return response.json();
                })
                .then(function (data) {
                    if (finished) return;
                    if (!data || !data.ok || !data.redirect) throw new Error('verification');
                    var target = new URL(data.redirect, window.location.href);
                    if (target.origin !== window.location.origin) throw new Error('redirect');
                    setState('success', 'Всё готово. Добро пожаловать!', 'Открываем страницу, на которую вы шли.');
                    finished = true;
                    cleanup();
                    // Do not hold the visitor for an animation: preserve URL, campaign and referrer.
                    if (target.pathname === window.location.pathname && target.search === window.location.search) {
                        window.location.reload();
                    } else {
                        window.location.replace(data.redirect);
                    }
                })
                .catch(function () {
                    fail('Не удалось подтвердить доступ', 'Проверьте соединение с интернетом и повторите проверку.');
                });
        }, Math.max(0, config.delay - (Date.now() - started)));
    }

    function fallback() {
        if (finished || proofReady || fallbackStarted) return;
        fallbackStarted = true;
        clearTimeout(workerTimer);
        if (worker) { worker.terminate(); worker = null; }
        if (!window.BotGuardPow || typeof window.BotGuardPow.solve !== 'function') {
            fail('Проверка не смогла запуститься', 'Разрешите JavaScript для этого сайта и повторите попытку.');
            return;
        }
        try {
            window.BotGuardPow.solve(config.seed, config.bits, complete);
        } catch (ignore) {
            fail('Проверка не смогла запуститься', 'Разрешите JavaScript для этого сайта и повторите попытку.');
        }
    }
    if (typeof Worker === 'function') {
        try {
            worker = new Worker(config.worker);
            workerTimer = setTimeout(function () { complete(new Error('timeout')); }, 22000);
            worker.onmessage = function (event) {
                var data = event.data || {};
                complete(data.error, data.solution);
            };
            worker.onerror = function (event) {
                if (event && event.preventDefault) event.preventDefault();
                fallback();
            };
            worker.postMessage({seed:config.seed, bits:config.bits});
            return;
        } catch (ignore) {}
    }
    fallback();
})();
