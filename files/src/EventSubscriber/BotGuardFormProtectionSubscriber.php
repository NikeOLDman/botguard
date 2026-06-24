<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\BotGuard\Form\BotGuardFormProtectionService;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class BotGuardFormProtectionSubscriber implements EventSubscriberInterface
{
    /**
     * @var BotGuardFormProtectionService
     */
    private $protection;

    /**
     * @var Connection
     */
    private $connection;

    public function __construct(BotGuardFormProtectionService $protection, Connection $connection)
    {
        $this->protection = $protection;
        $this->connection = $connection;
    }

    public static function getSubscribedEvents(): array
    {
        // After RouterListener (32): _route must be resolved before isProtectedRequest().
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 31],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->isMainRequest($event)) {
            return;
        }

        $request = $event->getRequest();

        if (0 === strpos((string) $request->getPathInfo(), '/admin')) {
            return;
        }

        if (!$this->protection->isProtectedRequest($request)) {
            return;
        }

        $reason = $this->protection->validate($request);
        if (null === $reason) {
            return;
        }

        if ($this->protection->shouldLog()) {
            $this->logBlockedAttempt($request, $reason);
        }

        $event->setResponse($this->protection->createRejectResponse($request, $reason));
    }

    /**
     * @param RequestEvent $event
     */
    private function isMainRequest($event): bool
    {
        if (method_exists($event, 'isMainRequest')) {
            return $event->isMainRequest();
        }

        return $event->isMasterRequest();
    }

    private function logBlockedAttempt(\Symfony\Component\HttpFoundation\Request $request, string $reason): void
    {
        try {
            $this->connection->insert('bot_guard_suspicious_event', [
                'ip' => $request->getClientIp(),
                'user_agent' => mb_substr((string) $request->headers->get('User-Agent', ''), 0, 1024),
                'method' => $request->getMethod(),
                'uri' => mb_substr((string) $request->getRequestUri(), 0, 255),
                'reason' => 'form_'.$reason,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // ignore logging errors
        }
    }
}
