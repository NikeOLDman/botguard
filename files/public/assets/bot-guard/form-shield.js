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

    var THEMES = {
        blue: {
            panelTop: '#f8fbff',
            border: '#bfd3f5',
            shadow: 'rgba(26,77,122,.16)',
            bar: 'linear-gradient(90deg,#1a4d7a,#2563eb,#38bdf8)',
            logoShadow: 'rgba(37,99,235,.16)',
            checkBorder: '#bfd3f5',
            checkBorderHover: '#7ba7ef',
            checkHoverRing: 'rgba(37,99,235,.08)',
            checkCheckedBorder: '#3b82f6',
            checkCheckedBg: 'rgba(37,99,235,.06)',
            checkCheckedRing: 'rgba(37,99,235,.12)',
            boxBorder: '#7ba7ef',
            primary: '#2563eb',
            label: '#1a4d7a',
            hint: '#64748b',
            footer: '#94a3b8',
            spinnerTrack: '#bfdbfe'
        },
        red: {
            panelTop: '#fff8f8',
            border: '#f3c6cb',
            shadow: 'rgba(237,30,52,.14)',
            bar: 'linear-gradient(90deg,#b81628,#ED1E34,#f06b7a)',
            logoShadow: 'rgba(237,30,52,.16)',
            checkBorder: '#f0b8bf',
            checkBorderHover: '#e88a94',
            checkHoverRing: 'rgba(237,30,52,.08)',
            checkCheckedBorder: '#ED1E34',
            checkCheckedBg: 'rgba(237,30,52,.05)',
            checkCheckedRing: 'rgba(237,30,52,.1)',
            boxBorder: '#e88a94',
            primary: '#ED1E34',
            label: '#9b1c2c',
            hint: '#8b5a62',
            footer: '#b07a82',
            spinnerTrack: '#f5c4ca'
        },
        cyan: {
            panelTop: '#f0fdfa',
            border: '#a5f3fc',
            shadow: 'rgba(8,145,178,.14)',
            bar: 'linear-gradient(90deg,#0e7490,#0891b2,#22d3ee)',
            logoShadow: 'rgba(8,145,178,.16)',
            checkBorder: '#a5f3fc',
            checkBorderHover: '#67e8f9',
            checkHoverRing: 'rgba(8,145,178,.08)',
            checkCheckedBorder: '#0891b2',
            checkCheckedBg: 'rgba(8,145,178,.06)',
            checkCheckedRing: 'rgba(8,145,178,.12)',
            boxBorder: '#67e8f9',
            primary: '#0891b2',
            label: '#155e75',
            hint: '#5b7f8a',
            footer: '#7a9da8',
            spinnerTrack: '#cffafe'
        },
        green: {
            panelTop: '#f0fdf4',
            border: '#bbf7d0',
            shadow: 'rgba(22,163,74,.14)',
            bar: 'linear-gradient(90deg,#15803d,#16a34a,#4ade80)',
            logoShadow: 'rgba(22,163,74,.16)',
            checkBorder: '#bbf7d0',
            checkBorderHover: '#86efac',
            checkHoverRing: 'rgba(22,163,74,.08)',
            checkCheckedBorder: '#16a34a',
            checkCheckedBg: 'rgba(22,163,74,.06)',
            checkCheckedRing: 'rgba(22,163,74,.12)',
            boxBorder: '#86efac',
            primary: '#16a34a',
            label: '#166534',
            hint: '#5f7a66',
            footer: '#7a9480',
            spinnerTrack: '#dcfce7'
        },
        orange: {
            panelTop: '#fff7ed',
            border: '#fed7aa',
            shadow: 'rgba(234,88,12,.14)',
            bar: 'linear-gradient(90deg,#c2410c,#ea580c,#fb923c)',
            logoShadow: 'rgba(234,88,12,.16)',
            checkBorder: '#fed7aa',
            checkBorderHover: '#fdba74',
            checkHoverRing: 'rgba(234,88,12,.08)',
            checkCheckedBorder: '#ea580c',
            checkCheckedBg: 'rgba(234,88,12,.06)',
            checkCheckedRing: 'rgba(234,88,12,.12)',
            boxBorder: '#fdba74',
            primary: '#ea580c',
            label: '#9a3412',
            hint: '#8a6a55',
            footer: '#a0806a',
            spinnerTrack: '#ffedd5'
        }
    };

    function resolveTheme(theme) {
        return THEMES[theme] ? theme : 'blue';
    }

    function applyTheme(overlay, themeName) {
        var theme = THEMES[resolveTheme(themeName)];

        overlay.style.setProperty('--bg-sh-panel-top', theme.panelTop);
        overlay.style.setProperty('--bg-sh-border', theme.border);
        overlay.style.setProperty('--bg-sh-shadow', theme.shadow);
        overlay.style.setProperty('--bg-sh-bar', theme.bar);
        overlay.style.setProperty('--bg-sh-logo-shadow', theme.logoShadow);
        overlay.style.setProperty('--bg-sh-check-border', theme.checkBorder);
        overlay.style.setProperty('--bg-sh-check-border-hover', theme.checkBorderHover);
        overlay.style.setProperty('--bg-sh-check-hover-ring', theme.checkHoverRing);
        overlay.style.setProperty('--bg-sh-check-checked-border', theme.checkCheckedBorder);
        overlay.style.setProperty('--bg-sh-check-checked-bg', theme.checkCheckedBg);
        overlay.style.setProperty('--bg-sh-check-checked-ring', theme.checkCheckedRing);
        overlay.style.setProperty('--bg-sh-box-border', theme.boxBorder);
        overlay.style.setProperty('--bg-sh-primary', theme.primary);
        overlay.style.setProperty('--bg-sh-label', theme.label);
        overlay.style.setProperty('--bg-sh-hint', theme.hint);
        overlay.style.setProperty('--bg-sh-footer', theme.footer);
        overlay.style.setProperty('--bg-sh-spinner-track', theme.spinnerTrack);
    }

    function getConfig() {
        return window.__bgFormShield || null;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
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
            + 'form[data-bg-shield-active]{position:relative;overflow:hidden}'
            + 'form[data-bg-shield-active] > :not(.bg-form-shield){filter:blur(1.25px) saturate(.88);opacity:.72;'
            + 'transition:filter .25s ease,opacity .25s ease;pointer-events:none;user-select:none}'
            + '.bg-form-shield{position:absolute;inset:0;z-index:30;display:flex;align-items:center;justify-content:center;padding:12px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}'
            + '.bg-form-shield__backdrop{position:absolute;inset:0;background:rgba(255,255,255,.28);backdrop-filter:blur(2px) saturate(.92);'
            + '-webkit-backdrop-filter:blur(2px) saturate(.92);border-radius:inherit;pointer-events:none}'
            + '.bg-form-shield__panel{position:relative;z-index:1;width:100%;max-width:384px;background:linear-gradient(180deg,var(--bg-sh-panel-top) 0%,#fff 48%);border:1px solid var(--bg-sh-border);border-radius:0;padding:12px;'
            + 'box-shadow:0 12px 32px var(--bg-sh-shadow);animation:bg-form-shield-in .22s ease-out;overflow:hidden}'
            + '.bg-form-shield__panel::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:var(--bg-sh-bar)}'
            + '@keyframes bg-form-shield-in{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:none}}'
            + '.bg-form-shield__row{display:flex;align-items:center;gap:12px;width:100%}'
            + '.bg-form-shield__brand{flex:0 0 64px;width:64px;height:64px;display:flex;align-items:center;justify-content:center;align-self:center}'
            + '.bg-form-shield__logo{display:block;max-width:64px;max-height:64px;width:auto;height:auto;object-fit:contain;filter:drop-shadow(0 4px 10px var(--bg-sh-logo-shadow))}'
            + '.bg-form-shield__content{flex:1;min-width:0;display:flex;flex-direction:column}'
            + '.bg-form-shield__content-main{min-height:64px;display:flex;align-items:center;width:100%}'
            + '.bg-form-shield__check{display:flex;align-items:center;gap:12px;width:100%;margin:0;padding:14px 14px;'
            + 'border:1px solid var(--bg-sh-check-border);border-radius:0;background:#fff;cursor:pointer;transition:border-color .2s,box-shadow .2s,background .2s;user-select:none;text-align:left}'
            + '.bg-form-shield__check:hover:not(.is-loading):not(.is-success){border-color:var(--bg-sh-check-border-hover);box-shadow:0 0 0 3px var(--bg-sh-check-hover-ring)}'
            + '.bg-form-shield__check.is-checked:not(.is-success){border-color:var(--bg-sh-check-checked-border);background:var(--bg-sh-check-checked-bg);box-shadow:0 0 0 3px var(--bg-sh-check-checked-ring)}'
            + '.bg-form-shield__check.is-loading{pointer-events:none;opacity:.9}'
            + '.bg-form-shield__check.is-success{border:none;background:transparent;box-shadow:none;pointer-events:none;padding:0;justify-content:center;text-align:center}'
            + '.bg-form-shield__content-main.is-success{justify-content:center}'
            + '.bg-form-shield__check.is-success .bg-form-shield__check-mark{display:none}'
            + '.bg-form-shield__check.is-success .bg-form-shield__check-text{flex:0 0 auto;align-items:center;text-align:center}'
            + '.bg-form-shield__check-input{position:absolute;opacity:0;width:1px;height:1px;pointer-events:none}'
            + '.bg-form-shield__check-mark{flex:0 0 28px;width:28px;display:flex;align-items:center;justify-content:center;align-self:center}'
            + '.bg-form-shield__check-box{width:26px;height:26px;border:2px solid var(--bg-sh-box-border);border-radius:6px;background:#fff;display:flex;align-items:center;justify-content:center;transition:all .2s ease}'
            + '.bg-form-shield__check.is-checked .bg-form-shield__check-box,.bg-form-shield__check.is-success .bg-form-shield__check-box{border-color:var(--bg-sh-primary);background:var(--bg-sh-primary)}'
            + '.bg-form-shield__check-icon{width:14px;height:14px;stroke:#fff;stroke-width:3;fill:none;stroke-linecap:round;stroke-linejoin:round;opacity:0;transform:scale(.6);transition:opacity .18s ease,transform .18s ease}'
            + '.bg-form-shield__check.is-checked .bg-form-shield__check-icon,.bg-form-shield__check.is-success .bg-form-shield__check-icon{opacity:1;transform:scale(1)}'
            + '.bg-form-shield__check-text{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px;justify-content:center}'
            + '.bg-form-shield__check-label{display:block;font-size:14px;font-weight:600;line-height:1.3;color:var(--bg-sh-label)}'
            + '.bg-form-shield__check.is-success .bg-form-shield__check-label{color:#22c55e;font-size:16px;font-weight:700;animation:bg-form-shield-success-in .28s ease-out}'
            + '@keyframes bg-form-shield-success-in{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}'
            + '.bg-form-shield__check-hint{display:block;font-size:9px;font-weight:400;line-height:1.35;color:var(--bg-sh-hint)}'
            + '.bg-form-shield__check-spinner{width:26px;height:26px;border:2px solid var(--bg-sh-spinner-track);border-top-color:var(--bg-sh-primary);border-radius:50%;animation:bg-form-shield-spin .7s linear infinite;display:none}'
            + '.bg-form-shield__check.is-loading .bg-form-shield__check-spinner{display:block}'
            + '.bg-form-shield__check.is-loading .bg-form-shield__check-box{display:none}'
            + '.bg-form-shield__footer{margin:0px 0 0;padding:0;font-size:9px;line-height:1.3;color:var(--bg-sh-footer);text-align:right;letter-spacing:.01em;width:100%}'
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
        var brandName = (config && config.brandName) ? String(config.brandName) : '';
        var theme = (config && config.theme) ? config.theme : 'blue';

        form.setAttribute('data-bg-shield-active', '1');

        var overlay = document.createElement('div');
        overlay.id = OVERLAY_ID;
        overlay.className = 'bg-form-shield';
        applyTheme(overlay, theme);
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'bg-form-shield-title');
        overlay.innerHTML = ''
            + '<div class="bg-form-shield__backdrop"></div>'
            + '<div class="bg-form-shield__panel">'
            + '<div class="bg-form-shield__row">'
            + '<div class="bg-form-shield__brand">'
            + '<img class="bg-form-shield__logo" src="' + logoUrl + '" alt="' + escapeHtml(brandName) + '" loading="lazy">'
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
