<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Injects BotGuard AJAX HTML guard for catalogue filter fetch() calls.
 * Independent of site frontend assets (filter-smart.js stays untouched).
 */
final class BotGuardAjaxHtmlGuardAssetSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -510],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$this->isMainRequest($event)) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        if (!$this->shouldInjectAssets($request, $response)) {
            return;
        }

        $content = $response->getContent();
        if (!is_string($content) || false === stripos($content, '</body>')) {
            return;
        }

        // Only pages that actually host the smart filter.
        if (false === stripos($content, 'js-filter-smart')
            && false === stripos($content, 'products-filter__btn-apply')
        ) {
            return;
        }

        $config = [
            'pathNeedle' => '/filtered',
            'mainMarker' => 'l-main',
            'challengeMarker' => 'bg-challenge',
            'captchaField' => '_bgct',
            'headerName' => 'X-Bot-Guard',
        ];

        $injection = sprintf(
            '<script>window.__bgAjaxHtmlGuard=%s;</script><script src="/assets/bot-guard/ajax-html-guard.js"></script>',
            json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $response->setContent(str_ireplace('</body>', $injection.'</body>', $content));
    }

    private function shouldInjectAssets(Request $request, Response $response): bool
    {
        if (Request::METHOD_GET !== $request->getMethod()) {
            return false;
        }

        $path = (string) $request->getPathInfo();
        if (0 === strpos($path, '/admin') || 0 === strpos($path, '/_')) {
            return false;
        }

        if ($request->isXmlHttpRequest()) {
            return false;
        }

        $contentType = (string) $response->headers->get('Content-Type', '');

        return false !== stripos($contentType, 'text/html');
    }

    /**
     * @param ResponseEvent $event
     */
    private function isMainRequest($event): bool
    {
        if (method_exists($event, 'isMainRequest')) {
            return $event->isMainRequest();
        }

        return $event->isMasterRequest();
    }
}
