<?php

declare(strict_types=1);

namespace App\BotGuard;

/** Standalone presentation: no database, Twig, frontend build or third-party requests. */
final class BotGuardChallengePage
{
    public static function render(string $config): string
    {
        $html = <<<'HTML'
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="color-scheme" content="dark">
    <title>Проверка браузера</title>
    <link rel="stylesheet" href="/assets/bot-guard/challenge.css?v=20261118c">
    <noscript><style>.bg-visual *{animation:none!important}.bg-check,.bg-steps{display:none!important}</style></noscript>
</head>
<body>
    <div class="bg-page" id="bg-page" data-state="checking">
        <header class="bg-header">
            <div class="bg-brand"><img src="/assets/images/bot-guard/logo.png" width="38" height="38" alt=""><span>ТВЕРДЫНЯ<small>ЗАЩИТА САЙТА</small></span></div>
        </header>

        <main class="bg-main">
            <section class="bg-story" aria-labelledby="bg-status">
                <div class="bg-check" aria-live="polite" aria-atomic="true" role="status">
                    <span class="bg-check-indicator" aria-hidden="true"><span></span></span>
                    <div><h1 id="bg-status">Проверяем ваш браузер</h1><p id="bg-detail">Ничего нажимать не нужно — всё произойдёт автоматически.</p></div>
                </div>

                <ol class="bg-steps" aria-label="Этапы проверки">
                    <li id="bg-step-browser" class="is-active" aria-current="step"><span class="bg-step-number">01</span><span>Браузер</span></li>
                    <li id="bg-step-access"><span class="bg-step-number">02</span><span>Доступ</span></li>
                    <li id="bg-step-ready"><span class="bg-step-number">03</span><span>Переход</span></li>
                </ol>
                <button type="button" id="bg-retry" class="bg-retry" hidden>Повторить проверку <span aria-hidden="true">↗</span></button>
                <noscript><div class="bg-nojs"><strong>Включите JavaScript в браузере</strong><p>Он нужен для автоматической проверки. Затем обновите страницу.</p></div></noscript>
            </section>

            <div class="bg-visual" aria-hidden="true">
                <div class="bg-stage" id="bg-stage">
                    <div class="bg-ambient"></div>
                    <div class="bg-tilt" id="bg-tilt">
                        <div class="bg-floor"></div>
                        <div class="bg-orbit bg-orbit-back"><span class="bg-orbit-track"><i></i></span></div>
                        <div class="bg-orbit bg-orbit-front"><span class="bg-orbit-track"><i></i></span></div>
                        <div class="bg-guardian"><img src="/assets/images/bot-guard/shield.svg" width="246" height="246" alt="" draggable="false"></div>
                    </div>
                    <span class="bg-scene-cross bg-scene-cross-one"></span><span class="bg-scene-cross bg-scene-cross-two"></span>
                </div>
            </div>
        </main>

        <footer class="bg-footer"><p>Система защиты сайта <span aria-hidden="true">·</span> Безопасный доступ</p></footer>
    </div>
    <script>window.__bgChallenge=__BG_CONFIG__;</script>
    <script src="/assets/bot-guard/pow.js" defer></script>
    <script src="/assets/bot-guard/challenge.js?v=20261118b" defer></script>
    <script src="/assets/bot-guard/challenge-scene.js?v=20261118b" defer></script>
</body>
</html>
HTML;

        return str_replace('__BG_CONFIG__', $config, $html);
    }
}
