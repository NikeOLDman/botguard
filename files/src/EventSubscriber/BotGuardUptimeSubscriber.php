<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\BotGuard\BotGuardSiteHealthChecker;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** A trusted monitor receives fresh readiness results instead of a JS challenge. */
final class BotGuardUptimeSubscriber implements EventSubscriberInterface
{
    private $checker;
    private $logger;
    private $enabled;
    private $trustedIps;

    public function __construct(BotGuardSiteHealthChecker $checker, LoggerInterface $logger, bool $enabled, array $trustedIps)
    {
        $this->checker = $checker;
        $this->logger = $logger;
        $this->enabled = $enabled;
        $this->trustedIps = $trustedIps;
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 1050]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $main = method_exists($event, 'isMainRequest') ? $event->isMainRequest() : $event->isMasterRequest();
        $request = $event->getRequest();
        if (!$main || !$this->enabled || '/' !== $request->getPathInfo()
            || !in_array($request->getMethod(), [Request::METHOD_GET, Request::METHOD_HEAD], true)
            || 1 !== preg_match('#^Uptime-Kuma(?:/[0-9][A-Za-z0-9._-]*)?$#D', (string) $request->headers->get('User-Agent', ''))
            || !$this->isTrustedIp((string) $request->getClientIp())) {
            return;
        }

        $status = 200;
        $body = "ok\n";
        try {
            $this->checker->check();
        } catch (\Throwable $error) {
            $status = 503;
            $body = "unavailable\n";
            $this->logger->error('BotGuard readiness check failed.', ['exception' => $error]);
        }

        $response = new Response($request->isMethod(Request::METHOD_HEAD) ? '' : $body, $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, private, max-age=0',
            'X-Bot-Guard' => 'readiness',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        if (503 === $status) {
            $response->headers->set('Retry-After', '60');
        }
        $event->setResponse($response);
    }

    private function isTrustedIp(string $ip): bool
    {
        return '' !== $ip && [] !== $this->trustedIps && IpUtils::checkIp($ip, $this->trustedIps);
    }
}
