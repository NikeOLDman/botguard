<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\BotGuard\BotGuardDecider;
use App\Entity\BotGuard\BotGuardLog;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class BotGuardSubscriber implements EventSubscriberInterface
{
    private const LOG_DEDUP_TTL_SECONDS = 30;
    private const LOG_DEDUP_PREFIX = 'bot_guard.log_dedup.';
    private const SUSPICIOUS_DEDUP_TTL_SECONDS = 60;
    private const SUSPICIOUS_DEDUP_PREFIX = 'bot_guard.suspicious_dedup.';
    private const ACCESS_COOKIE_LIFETIME_SECONDS = 2592000;
    private const REQUEST_ATTR_SET_ACCESS_COOKIE = '_bot_guard_set_access_cookie';

    /**
     * @var BotGuardDecider
     */
    private $decider;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var CacheInterface|null
     */
    private $cache;

    public function __construct(BotGuardDecider $decider, EntityManagerInterface $em, Connection $connection, ?CacheInterface $cache = null)
    {
        $this->decider = $decider;
        $this->em = $em;
        $this->connection = $connection;
        $this->cache = $cache;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 1024],
            KernelEvents::RESPONSE => ['onKernelResponse', -1024],
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

        if ($this->hasAccessCookie($request) && '1' === (string) $request->query->get(BotGuardDecider::CHALLENGE_QUERY_PARAM, '')) {
            $event->setResponse($this->createChallengeCleanupResponse($request));

            return;
        }

        $decision = $this->decideSafely($request);

        if (!empty($decision['challenge'])) {
            $event->setResponse($this->createChallengeResponse($request));

            return;
        }

        if (!$decision['blocked']) {
            if (!$this->hasAccessCookie($request)) {
                $request->attributes->set(self::REQUEST_ATTR_SET_ACCESS_COOKIE, true);
            }

            if ($this->isLoggingEnabledSafely()) {
                $this->logSuspiciousUnblockedRequest($request);
            }

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

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$this->isMainRequest($event)) {
            return;
        }

        $request = $event->getRequest();

        if (0 === strpos((string) $request->getPathInfo(), '/admin')) {
            return;
        }

        if (true !== $request->attributes->get(self::REQUEST_ATTR_SET_ACCESS_COOKIE, false)) {
            return;
        }

        if ($this->responseHasAccessCookie($event->getResponse())) {
            return;
        }

        $event->getResponse()->headers->setCookie($this->createAccessCookie($request));
    }

    private function isMainRequest($event): bool
    {
        if (method_exists($event, 'isMainRequest')) {
            return $event->isMainRequest();
        }

        return $event->isMasterRequest();
    }

    /**
     * @return array{blocked: bool, challenge: bool, reason: ?string, ruleName: ?string, rulePattern: ?string, statusCode: int}
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
                    'challenge' => false,
                    'reason' => 'empty_user_agent_fallback',
                    'ruleName' => null,
                    'rulePattern' => null,
                    'statusCode' => Response::HTTP_FORBIDDEN,
                ];
            }

            return [
                'blocked' => false,
                'challenge' => false,
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
     * @param array{blocked: bool, challenge: bool, reason: ?string, ruleName: ?string, rulePattern: ?string, statusCode: int} $decision
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

    private function logSuspiciousUnblockedRequest(Request $request): void
    {
        $userAgent = (string) $request->headers->get('User-Agent', '');
        if ($this->isUserAgentWhitelistedSafely($userAgent)) {
            return;
        }

        $reason = $this->detectSuspiciousReason($request);

        if (null === $reason) {
            return;
        }

        $signature = implode('|', [
            (string) $request->getClientIp(),
            mb_strtolower(trim((string) $request->headers->get('User-Agent', ''))),
            $reason,
        ]);
        $key = self::SUSPICIOUS_DEDUP_PREFIX.hash('sha256', $signature);
        $marker = microtime(true);

        try {
            if (null !== $this->cache) {
                $value = $this->cache->get($key, function (ItemInterface $item) use ($marker): float {
                    $item->expiresAfter(self::SUSPICIOUS_DEDUP_TTL_SECONDS);

                    return $marker;
                });

                if ($value !== $marker) {
                    return;
                }
            }

            $this->connection->insert('bot_guard_suspicious_event', [
                'ip' => $request->getClientIp(),
                'user_agent' => mb_substr((string) $request->headers->get('User-Agent', ''), 0, 1024),
                'method' => $request->getMethod(),
                'uri' => mb_substr((string) $request->getRequestUri(), 0, 255),
                'reason' => $reason,
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Не ломаем запросы, если запись подозрительного события не удалась.
        }
    }

    private function detectSuspiciousReason(Request $request): ?string
    {
        $userAgent = trim((string) $request->headers->get('User-Agent', ''));
        $lowerUserAgent = mb_strtolower($userAgent);
        $uri = mb_strtolower((string) $request->getPathInfo());

        if ('' === $userAgent) {
            return 'suspicious_empty_user_agent';
        }

        if ('-' === $userAgent) {
            return 'suspicious_dash_user_agent';
        }

        if (mb_strlen($userAgent) <= 8) {
            return 'suspicious_short_user_agent';
        }

        foreach ([
            'bot',
            'crawler',
            'spider',
            'scrapy',
            'curl',
            'wget',
            'python-requests',
            'go-http-client',
            'okhttp',
            'libwww',
            'httpclient',
            'java/',
        ] as $needle) {
            if (false !== strpos($lowerUserAgent, $needle)) {
                return 'suspicious_user_agent_pattern';
            }
        }

        foreach ([
            '/wp-admin',
            '/wp-login',
            '/xmlrpc.php',
            '/.env',
            '/phpmyadmin',
            '/vendor/phpunit',
            '/boaform',
            '/cgi-bin/',
        ] as $probe) {
            if (false !== strpos($uri, $probe)) {
                return 'suspicious_uri_probe';
            }
        }

        return null;
    }

    private function isUserAgentWhitelistedSafely(string $userAgent): bool
    {
        try {
            return $this->decider->isUserAgentWhitelisted($userAgent);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array{blocked: bool, challenge: bool, reason: ?string, ruleName: ?string, rulePattern: ?string, statusCode: int} $decision
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

    private function hasAccessCookie(Request $request): bool
    {
        return '' !== trim((string) $request->cookies->get(BotGuardDecider::ACCESS_COOKIE_NAME, ''));
    }

    private function createChallengeResponse(Request $request): Response
    {
        $response = new Response('', Response::HTTP_FOUND, [
            'Location' => $this->buildChallengeTarget($request),
        ]);
        $response->headers->setCookie($this->createAccessCookie($request));

        return $response;
    }

    private function buildChallengeTarget(Request $request): string
    {
        $query = $request->query->all();
        $query[BotGuardDecider::CHALLENGE_QUERY_PARAM] = '1';
        $queryString = http_build_query($query);

        return $request->getPathInfo().('' !== $queryString ? '?'.$queryString : '');
    }

    private function createChallengeCleanupResponse(Request $request): Response
    {
        $query = $request->query->all();
        unset($query[BotGuardDecider::CHALLENGE_QUERY_PARAM]);
        $queryString = http_build_query($query);

        return new Response('', Response::HTTP_FOUND, [
            'Location' => $request->getPathInfo().('' !== $queryString ? '?'.$queryString : ''),
        ]);
    }

    private function createAccessCookie(Request $request): Cookie
    {
        return Cookie::create(
            BotGuardDecider::ACCESS_COOKIE_NAME,
            '1',
            new \DateTimeImmutable('+'.self::ACCESS_COOKIE_LIFETIME_SECONDS.' seconds'),
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX
        );
    }

    private function responseHasAccessCookie(Response $response): bool
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if (BotGuardDecider::ACCESS_COOKIE_NAME === $cookie->getName()) {
                return true;
            }
        }

        return false;
    }
}

