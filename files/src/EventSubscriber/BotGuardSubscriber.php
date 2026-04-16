<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\BotGuard\BotGuardDecider;
use App\Entity\BotGuard\BotGuardLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class BotGuardSubscriber implements EventSubscriberInterface
{
    private const LOG_DEDUP_TTL_SECONDS = 30;
    private const LOG_DEDUP_PREFIX = 'bot_guard.log_dedup.';

    /**
     * @var BotGuardDecider
     */
    private $decider;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var CacheInterface|null
     */
    private $cache;

    public function __construct(BotGuardDecider $decider, EntityManagerInterface $em, ?CacheInterface $cache = null)
    {
        $this->decider = $decider;
        $this->em = $em;
        $this->cache = $cache;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 1024],
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

        $decision = $this->decideSafely($request);

        if (!$decision['blocked']) {
            return;
        }

        if ($this->isLoggingEnabledSafely() && $this->shouldLogBlockedRequest($request, $decision)) {
            $this->logBlockedRequest($request, $decision);
        }

        $statusCode = (int) $decision['statusCode'];

        if ($statusCode < 400 || $statusCode > 599) {
            $statusCode = Response::HTTP_FORBIDDEN;
        }

        $event->setResponse(new Response('', $statusCode));
    }

    private function isMainRequest(RequestEvent $event): bool
    {
        if (method_exists($event, 'isMainRequest')) {
            return $event->isMainRequest();
        }

        return $event->isMasterRequest();
    }

    /**
     * @return array{blocked: bool, reason: ?string, ruleName: ?string, rulePattern: ?string, statusCode: int}
     */
    private function decideSafely(Request $request): array
    {
        try {
            return $this->decider->decide($request);
        } catch (\Throwable $e) {
            $userAgent = (string) $request->headers->get('User-Agent', '');

            if ('' === trim($userAgent)) {
                return [
                    'blocked' => true,
                    'reason' => 'empty_user_agent_fallback',
                    'ruleName' => null,
                    'rulePattern' => null,
                    'statusCode' => Response::HTTP_FORBIDDEN,
                ];
            }

            return [
                'blocked' => false,
                'reason' => null,
                'ruleName' => null,
                'rulePattern' => null,
                'statusCode' => Response::HTTP_FORBIDDEN,
            ];
        }
    }

    private function isLoggingEnabledSafely(): bool
    {
        try {
            return $this->decider->isLoggingEnabled();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array{blocked: bool, reason: ?string, ruleName: ?string, rulePattern: ?string, statusCode: int} $decision
     */
    private function shouldLogBlockedRequest(Request $request, array $decision): bool
    {
        if (null === $this->cache) {
            return true;
        }

        $signature = implode('|', [
            (string) $request->getClientIp(),
            (string) $request->getMethod(),
            (string) $request->getPathInfo(),
            (string) $request->headers->get('User-Agent', ''),
            (string) ($decision['reason'] ?? ''),
            (string) ($decision['ruleName'] ?? ''),
            (string) ($decision['rulePattern'] ?? ''),
        ]);
        $key = self::LOG_DEDUP_PREFIX.hash('sha256', $signature);
        $marker = microtime(true);

        try {
            $value = $this->cache->get($key, function (ItemInterface $item) use ($marker): float {
                $item->expiresAfter(self::LOG_DEDUP_TTL_SECONDS);

                return $marker;
            });
        } catch (\Throwable $e) {
            return true;
        }

        return $value === $marker;
    }

    /**
     * @param array{blocked: bool, reason: ?string, ruleName: ?string, rulePattern: ?string, statusCode: int} $decision
     */
    private function logBlockedRequest(Request $request, array $decision): void
    {
        try {
            $log = (new BotGuardLog())
                ->setReason((string) $decision['reason'])
                ->setRuleName($decision['ruleName'])
                ->setRulePattern($decision['rulePattern'])
                ->setIp($request->getClientIp())
                ->setMethod($request->getMethod())
                ->setUri(mb_substr((string) $request->getRequestUri(), 0, 255))
                ->setUserAgent(mb_substr((string) $request->headers->get('User-Agent', ''), 0, 1024))
                ->setStatusCode((int) $decision['statusCode']);

            $this->em->persist($log);
            $this->em->flush($log);
        } catch (\Throwable $e) {
            // Не прерываем запрос, если логирование не удалось.
        }
    }
}

