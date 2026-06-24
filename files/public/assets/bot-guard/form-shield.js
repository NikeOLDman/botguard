(function (window, document) {
    'use strict';

    var OVERLAY_ID = 'bg-form-shield-overlay';
    var CART_MICROFORM_CLASSES = [
        'js-cart-add',
        'js-cart-delete',
        'js-cart-count-change',
        'js-cart-clear',
        'js-cart-batch-delete'
    ];
    var bypassForms = typeof WeakSet !== 'undefined' ? new WeakSet() : null;
    var stylesInjected = false;

    function getConfig() {
        return window.__bgFormShield || null;
    }

    function getJQuery() {
        if (window.jQuery) {
            return window.jQuery;
        }

        if (window.$ && window.$.fn) {
            return window.$;
        }

        return null;
    }

    function injectStyles() {
        if (stylesInjected) {
            return;
        }

        stylesInjected = true;

        var style = document.createElement('style');
        style.textContent = ''
            + '.bg-form-shield,.bg-form-shield *{box-sizing:border-box}'
            + 'form[data-bg-shield-active]{position:relative}'
            + '.bg-form-shield{position:absolute;inset:0;z-index:30;display:flex;align-items:center;justify-content:center;padding:12px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}'
            + '.bg-form-shield__backdrop{position:absolute;inset:0;background:rgba(15,23,42,.48);border-radius:inherit}'
            + '.bg-form-shield__panel{position:relative;z-index:1;width:100%;max-width:384px;background:linear-gradient(180deg,#f8fbff 0%,#fff 42%);border-radius:0;padding:12px;'
            + 'box-shadow:0 12px 32px rgba(26,77,122,.16);animation:bg-form-shield-in .22s ease-out;overflow:hidden}'
            + '.bg-form-shield__panel::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#1a4d7a,#2563eb,#38bdf8)}'
            + '@keyframes bg-form-shield-in{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:none}}'
            + '.bg-form-shield__row{display:flex;align-items:flex-start;gap:12px;width:100%}'
            + '.bg-form-shield__brand{flex:0 0 96px;width:96px;display:flex;align-items:center;justify-content:flex-start;align-self:flex-start;min-height:96px}'
            + '.bg-form-shield__logo{width:96px;height:96px;object-fit:contain;display:block;filter:drop-shadow(0 4px 10px rgba(37,99,235,.18))}'
            + '.bg-form-shield__content{flex:1;min-width:0;display:flex;flex-direction:column}'
            + '.bg-form-shield__content-main{min-height:86px;display:flex;align-items:center;width:100%}'
            + '.bg-form-shield__check{display:flex;align-items:center;gap:12px;width:100%;margin:0;padding:14px 14px;'
            + 'border:1px solid #bfd3f5;border-radius:0;background:#fff;cursor:pointer;transition:border-color .2s,box-shadow .2s,background .2s;user-select:none;text-align:left}'
            + '.bg-form-shield__check:hover:not(.is-loading):not(.is-success){border-color:#7ba7ef;box-shadow:0 0 0 3px rgba(37,99,235,.08)}'
            + '.bg-form-shield__check.is-checked:not(.is-success){border-color:#3b82f6;background:rgba(37,99,235,.06);box-shadow:0 0 0 3px rgba(37,99,235,.12)}'
            + '.bg-form-shield__check.is-loading{pointer-events:none;opacity:.9}'
            + '.bg-form-shield__check.is-success{border:none;background:transparent;box-shadow:none;pointer-events:none;padding:0;justify-content:center;text-align:center}'
            + '.bg-form-shield__content-main.is-success{justify-content:center}'
            + '.bg-form-shield__check.is-success .bg-form-shield__check-mark{display:none}'
            + '.bg-form-shield__check.is-success .bg-form-shield__check-text{flex:0 0 auto;align-items:center;text-align:center}'
            + '.bg-form-shield__check-input{position:absolute;opacity:0;width:1px;height:1px;pointer-events:none}'
            + '.bg-form-shield__check-mark{flex:0 0 28px;width:28px;display:flex;align-items:center;justify-content:center;align-self:center}'
            + '.bg-form-shield__check-box{width:26px;height:26px;border:2px solid #7ba7ef;border-radius:6px;background:#fff;display:flex;align-items:center;justify-content:center;transition:all .2s ease}'
            + '.bg-form-shield__check.is-checked .bg-form-shield__check-box,.bg-form-shield__check.is-success .bg-form-shield__check-box{border-color:#2563eb;background:#2563eb}'
            + '.bg-form-shield__check-icon{width:14px;height:14px;stroke:#fff;stroke-width:3;fill:none;stroke-linecap:round;stroke-linejoin:round;opacity:0;transform:scale(.6);transition:opacity .18s ease,transform .18s ease}'
            + '.bg-form-shield__check.is-checked .bg-form-shield__check-icon,.bg-form-shield__check.is-success .bg-form-shield__check-icon{opacity:1;transform:scale(1)}'
            + '.bg-form-shield__check-text{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px;justify-content:center}'
            + '.bg-form-shield__check-label{display:block;font-size:14px;font-weight:600;line-height:1.3;color:#1a4d7a}'
            + '.bg-form-shield__check.is-success .bg-form-shield__check-label{color:#22c55e;font-size:16px;font-weight:700;animation:bg-form-shield-success-in .28s ease-out}'
            + '@keyframes bg-form-shield-success-in{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}'
            + '.bg-form-shield__check-hint{display:block;font-size:9px;font-weight:400;line-height:1.35;color:#64748b}'
            + '.bg-form-shield__check-spinner{width:26px;height:26px;border:2px solid #bfdbfe;border-top-color:#2563eb;border-radius:50%;animation:bg-form-shield-spin .7s linear infinite;display:none}'
            + '.bg-form-shield__check.is-loading .bg-form-shield__check-spinner{display:block}'
            + '.bg-form-shield__check.is-loading .bg-form-shield__check-box{display:none}'
            + '.bg-form-shield__footer{margin:0px 0 0;padding:0;font-size:9px;line-height:1.3;color:#94a3b8;text-align:right;letter-spacing:.01em;width:100%}'
            + '@keyframes bg-form-shield-spin{to{transform:rotate(360deg)}}';

        document.head.appendChild(style);
    }

    function normalizeFormAction(action) {
        var raw = (action || '').trim();

        if (!raw) {
            return window.location.pathname;
        }

        try {
            return new URL(raw, window.location.origin).pathname;
        } catch (error) {
            var path = raw.split('?')[0];
            return path.charAt(0) === '/' ? path : '/' + path;
        }
    }

    function hasClass(el, className) {
        return el.classList && el.classList.contains(className);
    }

    function isCartMicroForm(form) {
        for (var i = 0; i < CART_MICROFORM_CLASSES.length; i++) {
            if (hasClass(form, CART_MICROFORM_CLASSES[i])) {
                return true;
            }
        }
        return false;
    }

    function closest(el, selector) {
        while (el && el.nodeType === 1) {
            if (el.matches && el.matches(selector)) {
                return el;
            }
            el = el.parentElement;
        }
        return null;
    }

    function resolveSubmitForm(target) {
        if (!target || target.nodeType !== 1) {
            return null;
        }

        if (target.matches && target.matches('form.js-ajax')) {
            return target;
        }

        return closest(target, 'form.js-ajax');
    }

    function isProtectedLeadForm(form) {
        var config = getConfig();

        if (!config || !config.enabled || isCartMicroForm(form)) {
            return false;
        }

        var action = normalizeFormAction(form.getAttribute('action') || '');

        if (action.indexOf('/submit') !== -1) {
            return true;
        }

        return !!config.protectCheckout && !!closest(form, '.js-order-checkout');
    }

    function ensureIssuedAt(form) {
        var issuedAt = form.getAttribute('data-bg-issued-at');

        if (!issuedAt) {
            issuedAt = String(Math.floor(Date.now() / 1000));
            form.setAttribute('data-bg-issued-at', issuedAt);
        }

        return parseInt(issuedAt, 10);
    }

    function clearShieldFields(form, config) {
        var names = [config.tokenField, config.issuedAtField, config.confirmedAtField];
        var inputs = form.querySelectorAll('input[type="hidden"]');
        var i;

        for (i = 0; i < inputs.length; i++) {
            if (names.indexOf(inputs[i].name) !== -1) {
                inputs[i].parentNode.removeChild(inputs[i]);
            }
        }
    }

    function appendShieldFields(form, config, token, issuedAt, confirmedAt) {
        clearShieldFields(form, config);

        function add(name, value) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = String(value);
            form.appendChild(input);
        }

        add(config.tokenField, token);
        add(config.issuedAtField, issuedAt);
        add(config.confirmedAtField, confirmedAt);
    }

    function removeOverlay() {
        var overlay = document.getElementById(OVERLAY_ID);
        if (!overlay) {
            return;
        }

        var form = overlay.closest('form.js-ajax');

        if (overlay.parentNode) {
            overlay.parentNode.removeChild(overlay);
        }

        if (form) {
            form.removeAttribute('data-bg-shield-active');
        }
    }

    function showOverlay(config, form) {
        injectStyles();
        removeOverlay();

        var logoUrl = (config && config.logoUrl) ? config.logoUrl : '/assets/images/bot-guard/logo.png';

        form.setAttribute('data-bg-shield-active', '1');

        var overlay = document.createElement('div');
        overlay.id = OVERLAY_ID;
        overlay.className = 'bg-form-shield';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'bg-form-shield-title');
        overlay.innerHTML = ''
            + '<div class="bg-form-shield__backdrop"></div>'
            + '<div class="bg-form-shield__panel">'
            + '<div class="bg-form-shield__row">'
            + '<div class="bg-form-shield__brand">'
            + '<img class="bg-form-shield__logo" src="' + logoUrl + '" alt="" width="96" height="96" loading="lazy">'
            + '</div>'
            + '<div class="bg-form-shield__content">'
            + '<div class="bg-form-shield__content-main">'
            + '<label class="bg-form-shield__check">'
            + '<input type="checkbox" class="bg-form-shield__check-input">'
            + '<span class="bg-form-shield__check-mark">'
            + '<span class="bg-form-shield__check-box" aria-hidden="true">'
            + '<svg class="bg-form-shield__check-icon" viewBox="0 0 16 16"><path d="M3 8.5l3 3 7-7"/></svg>'
            + '</span>'
            + '<span class="bg-form-shield__check-spinner" aria-hidden="true"></span>'
            + '</span>'
            + '<span class="bg-form-shield__check-text">'
            + '<span id="bg-form-shield-title" class="bg-form-shield__check-label">Я не робот</span>'
            + '<span class="bg-form-shield__check-hint">Поставьте галочку, чтобы отправить заявку</span>'
            + '</span>'
            + '</label>'
            + '</div>'
            + '<p class="bg-form-shield__footer">Комплексная система защиты сайта от спама</p>'
            + '</div>'
            + '</div>'
            + '</div>';

        form.appendChild(overlay);

        return overlay;
    }

    function requestToken(config, formAction, issuedAt) {
        var body = new URLSearchParams();
        body.set('formAction', formAction);
        body.set(config.issuedAtField, String(issuedAt));

        return fetch(config.tokenUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false };
            }).then(function (data) {
                if (!response.ok) {
                    return { ok: false };
                }

                return data;
            });
        });
    }

    function waitMs(ms) {
        return new Promise(function (resolve) {
            window.setTimeout(resolve, ms);
        });
    }

    function notifyError(message) {
        if (window.App && typeof window.App.notify === 'function') {
            var text = message;
            if (typeof window.App.translate === 'function') {
                text = window.App.translate(message);
            }
            window.App.notify(text, 'error');
            return;
        }

        window.alert(message);
    }

    function canProceedToShield(form) {
        if (form.getAttribute('data-validation') !== 'true') {
            return true;
        }

        if (typeof window.BotGuardFormValidate === 'function') {
            return window.BotGuardFormValidate(form) !== false;
        }

        var event = new CustomEvent('bot-guard:validate', {
            bubbles: false,
            cancelable: true,
            detail: { form: form }
        });

        return form.dispatchEvent(event);
    }

    function markBypass(form) {
        if (bypassForms) {
            bypassForms.add(form);
            return;
        }

        form.setAttribute('data-bg-shield-passed', '1');
    }

    function consumeBypass(form) {
        if (bypassForms) {
            if (bypassForms.has(form)) {
                bypassForms.delete(form);
                return true;
            }

            return false;
        }

        if (form.getAttribute('data-bg-shield-passed') === '1') {
            form.removeAttribute('data-bg-shield-passed');
            return true;
        }

        return false;
    }

    function resubmitForm(form) {
        markBypass(form);

        var $ = getJQuery();
        if ($) {
            $(form).trigger('submit');
            return;
        }

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
            return;
        }

        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    }

    function runShield(form, onReady) {
        var config = getConfig();
        if (!config) {
            notifyError('form_shield.rejected');
            return;
        }

        var issuedAt = ensureIssuedAt(form);
        var formAction = normalizeFormAction(form.getAttribute('action') || '');
        var overlay = showOverlay(config, form);
        var checkRow = overlay.querySelector('.bg-form-shield__check');
        var checkbox = overlay.querySelector('.bg-form-shield__check-input');
        var checkLabel = overlay.querySelector('.bg-form-shield__check-label');
        var contentMain = overlay.querySelector('.bg-form-shield__content-main');
        var processing = false;

        function startVerification() {
            if (processing || !checkbox.checked) {
                return;
            }

            processing = true;
            checkbox.disabled = true;
            checkRow.classList.add('is-checked', 'is-loading');
            if (checkLabel) {
                checkLabel.textContent = 'Проверка…';
            }
            if (checkRow.querySelector('.bg-form-shield__check-hint')) {
                checkRow.querySelector('.bg-form-shield__check-hint').style.display = 'none';
            }

            var confirmedAt = Math.floor(Date.now() / 1000);
            var minDelay = Number(config.minConfirmDelayMs) || 400;
            var elapsedMs = Math.max(0, (confirmedAt - issuedAt) * 1000);
            var waitFor = Math.max(0, minDelay - elapsedMs);

            waitMs(waitFor)
                .then(function () {
                    return requestToken(config, formAction, issuedAt);
                })
                .then(function (data) {
                    if (!data || !data.ok || !data.token) {
                        throw new Error('token_request_failed');
                    }

                    appendShieldFields(form, config, data.token, issuedAt, confirmedAt);

                    checkRow.classList.remove('is-loading');
                    checkRow.classList.add('is-success', 'is-checked');
                    if (contentMain) {
                        contentMain.classList.add('is-success');
                    }
                    if (checkLabel) {
                        checkLabel.textContent = 'Успешно!';
                    }
                    if (checkRow.querySelector('.bg-form-shield__check-hint')) {
                        checkRow.querySelector('.bg-form-shield__check-hint').style.display = 'none';
                    }

                    return waitMs(750);
                })
                .then(function () {
                    removeOverlay();
                    onReady();
                })
                .catch(function () {
                    removeOverlay();
                    notifyError('form_shield.rejected');
                });
        }

        checkbox.addEventListener('change', function () {
            if (checkbox.checked) {
                startVerification();
                return;
            }

            if (!processing) {
                checkRow.classList.remove('is-checked');
            }
        });
    }

    function initForms(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var forms = scope.querySelectorAll ? scope.querySelectorAll('form.js-ajax') : [];

        for (var i = 0; i < forms.length; i++) {
            ensureIssuedAt(forms[i]);
        }
    }

    function onDocumentSubmit(event) {
        var form = resolveSubmitForm(event.target);

        if (!form) {
            return;
        }

        if (consumeBypass(form)) {
            return;
        }

        if (!isProtectedLeadForm(form)) {
            return;
        }

        if (!canProceedToShield(form)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        runShield(form, function () {
            resubmitForm(form);
        });
    }

    document.addEventListener('submit', onDocumentSubmit, true);
    initForms(document);

    if (window.App && window.App.onAjaxStream && typeof window.App.onAjaxStream.push === 'function') {
        window.App.onAjaxStream.push(initForms);
    } else {
        document.addEventListener('DOMContentLoaded', function () {
            initForms(document);
        });
    }
}(window, document));
