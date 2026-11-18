<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\BotGuard\BotGuardDecider;
use App\BotGuard\BotGuardIpFingerprint;
use App\BotGuard\BotGuardJsChallengeService;
use App\BotGuard\BotGuardRateLimiter;
use App\BotGuard\BotGuardResponse;
use App\BotGuard\BotGuardRequestPolicy;
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
    private const CAPTCHA_QUERY_PARAM = '_bgcc';
    private const REQUEST_ATTR_COMPLETE_JS_CHALLENGE = '_bot_guard_complete_js_challenge';
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
    /**
     * @var string
     */
    private $appSecret;

    /**
     * @var BotGuardJsChallengeService
     */
    private $jsChallenge;

    /**
     * @var BotGuardRateLimiter
     */
    private $rateLimiter;
    private $requestPolicy;

    public function __construct(
        BotGuardDecider $decider,
        EntityManagerInterface $em,
        Connection $connection,
        string $appSecret,
        BotGuardJsChallengeService $jsChallenge,
        BotGuardRateLimiter $rateLimiter,
        BotGuardRequestPolicy $requestPolicy,
        ?CacheInterface $cache = null
    )
    {
        $this->decider = $decider;
        $this->em = $em;
        $this->connection = $connection;
        $this->appSecret = $appSecret;
        $this->jsChallenge = $jsChallenge;
        $this->rateLimiter = $rateLimiter;
        $this->requestPolicy = $requestPolicy;
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

        if ($this->requestPolicy->isIntegration($request)) {
            return;
        }

        $query = $request->query->all();
        foreach ([BotGuardDecider::CHALLENGE_QUERY_PARAM, self::CAPTCHA_QUERY_PARAM] as $flag) {
            if (isset($query[$flag]) && !is_scalar($query[$flag])) {
                $event->setResponse(new Response('Некорректные параметры запроса.', 400, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']));

                return;
            }
        }

        if ($this->isPathWithin($request, '/admin')) {
            if ($request->isMethod('POST') && in_array(rtrim($request->getPathInfo(), '/'), ['/admin/login', '/admin/login/check'], true)) {
                if ($this->rateLimiter->isExceeded($request, 'admin_login', 15, 60, false)) {
                    $event->setResponse(BotGuardResponse::rateLimited($request));
                }
            }
            return;
        }

        if ($this->isPathWithin($request, '/_bot-guard')) {
            if ($this->rateLimiter->isExceeded($request, 'bot_guard_endpoint', 30, 60, false)) {
                $event->setResponse(BotGuardResponse::rateLimited($request));
            }
            return;
        }

        if ($this->decider->isPathRateLimitExceeded($request)) {
            $event->setResponse(BotGuardResponse::rateLimited($request));

            return;
        }

        if ($this->isRateLimitExceeded($request)) {
            $event->setResponse(BotGuardResponse::rateLimited($request));

            return;
        }

        if ($this->isCaptchaAttempt($request)) {
            // The old arithmetic CAPTCHA must not bypass proof of work.
            $event->setResponse($this->createStrictJsChallengeResponse($request));

            return;
        }

        if ('1' === (string) $request->query->get(BotGuardDecider::CHALLENGE_QUERY_PARAM, '')) {
            if ($this->shouldCleanupChallengeQuery($request)) {
                $event->setResponse($this->createChallengeCleanupResponse($request));

                return;
            }
        }

        $decision = $this->decideSafely($request);

        if (!empty($decision['challenge'])) {
            if (!$this->shouldSkipChallengeByReferrer($request, $decision)) {
                $event->setResponse($this->createChallengeResponse($request, (string) ($decision['reason'] ?? '')));

                return;
            }
        }

        // A legacy _bgc=1 URL also needs proof when a strict rule is active
        // outside global under-attack mode. Do not strand it on an empty 403.
        if ('js_challenge_not_passed' === (string) ($decision['reason'] ?? '')) {
            $event->setResponse($this->createStrictJsChallengeResponse($request));

            return;
        }

        if (!$decision['blocked']) {
            if ($this->decider->canCompleteJsChallenge($request)) {
                $request->attributes->set(self::REQUEST_ATTR_COMPLETE_JS_CHALLENGE, true);
            } elseif ($this->decider->shouldIssueGlobalAccessCookie($request)) {
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

        $headers = ['Cache-Control' => 'no-store, private'];
        if (503 === $statusCode) {
            $headers['Retry-After'] = '60';
        }
        $event->setResponse(new Response('', $statusCode, $headers));
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$this->isMainRequest($event)) {
            return;
        }

        $request = $event->getRequest();

        // Public XML/text and readiness responses must remain free of access cookies.
        if (BotGuardRequestPolicy::isSeoFile($request) || 'readiness' === $event->getResponse()->headers->get('X-Bot-Guard')) {
            return;
        }

        if ($this->requestPolicy->isIntegration($request)) {
            return;
        }

        if ($this->isPathWithin($request, '/admin')) {
            return;
        }

        if ($this->isPathWithin($request, '/_bot-guard')) {
            return;
        }

        if (true === $request->attributes->get(self::REQUEST_ATTR_COMPLETE_JS_CHALLENGE, false)) {
            $response = $event->getResponse();
            $response->headers->setCookie($this->createAccessCookie($request));
            $response->headers->setCookie($this->createJsChallengeCookie($request));
            $response->headers->clearCookie(BotGuardDecider::CHALLENGE_COOKIE_NAME, '/');

            if ($request->isMethod(Request::METHOD_GET) && '1' === (string) $request->query->get(BotGuardDecider::CHALLENGE_QUERY_PARAM, '')) {
                $event->setResponse($this->createChallengeCleanupResponseWithCookies($request, $response));

                return;
            }

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

    private function isPathWithin(Request $request, string $prefix): bool
    {
        return $prefix === $request->getPathInfo() || 0 === strpos($request->getPathInfo(), $prefix.'/');
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
                'blocked' => true,
                'challenge' => false,
                'reason' => 'protection_unavailable',
                'ruleName' => null,
                'rulePattern' => null,
                'statusCode' => Response::HTTP_SERVICE_UNAVAILABLE,
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
        $probe = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => (string) $request->getClientIp()]);
        if ($this->rateLimiter->isExceeded($probe, 'blocked_log', 2, 60, false)
            || $this->rateLimiter->consume($probe, 'blocked_log_total', 'all', 60, 60)) {
            return false;
        }
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
        if ($this->decider->isRequestWhitelisted($request)) {
            return;
        }

        $reason = $this->detectSuspiciousReason($request);

        if (null === $reason) {
            return;
        }

        $probe = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => (string) $request->getClientIp()]);
        if ($this->rateLimiter->isExceeded($probe, 'suspicious_log', 2, 60, false)
            || $this->rateLimiter->consume($probe, 'suspicious_log_total', 'all', 60, 60)) {
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

    private function hasValidAccessCookie(Request $request): bool
    {
        try {
            return $this->decider->hasValidAccessCookie($request);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * В режиме «Под атакой» access-cookie без js-cookie недостаточна — иначе зацикливается JS-челлендж.
     */
    private function shouldCleanupChallengeQuery(Request $request): bool
    {
        if (!$this->hasValidAccessCookie($request)) {
            return false;
        }

        if ($this->isUnderAttackEnabled() || $this->decider->uriMatchesStrictCookieRule((string) $request->getPathInfo())) {
            try {
                return $this->decider->hasValidJsChallengeCookie($request);
            } catch (\Throwable $e) {
                return false;
            }
        }

        return true;
    }

    private function createChallengeResponse(Request $request, string $reason): Response
    {
        if ($this->decider->isStrictChallengeReason($reason) || $this->isUnderAttackEnabled()) {
            return $this->createStrictJsChallengeResponse($request);
        }

        $response = new Response('', Response::HTTP_FOUND, [
            'Location' => $this->buildChallengeTarget($request),
        ]);
        $response->headers->setCookie($this->createAccessCookie($request));

        return $response;
    }

    private function createStrictJsChallengeResponse(Request $request): Response
    {
        // Preserve query order/encoding so the browser can reload the same history
        // entry and keep its original search/ad referrer after verification.
        $returnPath = $request->getRequestUri();

        $issued = $this->jsChallenge->issue($request, $returnPath, $this->decider->getJsChallengeMinDelayMs());
        $html = $this->jsChallenge->buildChallengeHtml(
            $issued['nonce'],
            $issued['returnPath'],
            $this->decider->getJsChallengeMinDelayMs()
        );

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            // Used by public/assets/bot-guard/ajax-html-guard.js for filter fetch().
            'X-Bot-Guard' => 'challenge',
        ]);
    }

    /**
     * @param array{blocked: bool, challenge: bool, reason: ?string, ruleName: ?string, rulePattern: ?string, statusCode: int} $decision
     */
    private function shouldSkipChallengeByReferrer(Request $request, array $decision): bool
    {
        if ('1' === (string) $request->query->get(BotGuardDecider::CHALLENGE_QUERY_PARAM, '')) {
            return false;
        }

        try {
            return $this->decider->shouldSkipChallengeByReferrer($request, (string) ($decision['reason'] ?? ''));
        } catch (\Throwable $e) {
            return false;
        }
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
        return new Response('', Response::HTTP_FOUND, [
            'Location' => $this->buildChallengeCleanupLocation($request),
        ]);
    }

    private function createChallengeCleanupResponseWithCookies(Request $request, Response $sourceResponse): Response
    {
        $response = new Response('', Response::HTTP_FOUND, [
            'Location' => $this->buildChallengeCleanupLocation($request),
        ]);

        foreach ($sourceResponse->headers->getCookies() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    private function buildChallengeCleanupLocation(Request $request): string
    {
        $query = $request->query->all();
        unset($query[BotGuardDecider::CHALLENGE_QUERY_PARAM]);
        unset($query[self::CAPTCHA_QUERY_PARAM]);
        $queryString = http_build_query($query);

        return $request->getPathInfo().('' !== $queryString ? '?'.$queryString : '');
    }

    private function isCaptchaAttempt(Request $request): bool
    {
        return $this->isUnderAttackEnabled()
            && $request->isMethod(Request::METHOD_POST)
            && '1' === (string) $request->query->get(self::CAPTCHA_QUERY_PARAM, '');
    }

    private function isRateLimitExceeded(Request $request): bool
    {
        try {
            if (!$this->decider->isRateLimitEnabled()) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        if ($this->decider->isRequestWhitelisted($request)) {
            return false;
        }

        $config = $this->decider->getRateLimitConfig();

        return $this->rateLimiter->isExceeded(
            $request,
            'global',
            (int) $config['maxRequests'],
            (int) $config['windowSeconds'],
            false
        );
    }

    private function createAccessCookie(Request $request): Cookie
    {
        return Cookie::create(
            BotGuardDecider::ACCESS_COOKIE_NAME,
            $this->decider->buildAccessCookieValue($request),
            new \DateTimeImmutable('+'.self::ACCESS_COOKIE_LIFETIME_SECONDS.' seconds'),
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX
        );
    }

    private function createJsChallengeCookie(Request $request): Cookie
    {
        return Cookie::create(
            BotGuardDecider::JS_COOKIE_NAME,
            $this->decider->buildJsCookieValue($request),
            new \DateTimeImmutable('+900 seconds'),
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX
        );
    }

    private function isUnderAttackEnabled(): bool
    {
        try {
            return $this->decider->isUnderAttackEnabled();
        } catch (\Throwable $e) {
            return false;
        }
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

