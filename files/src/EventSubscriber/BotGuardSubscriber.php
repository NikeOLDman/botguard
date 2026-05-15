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
    private const CAPTCHA_QUERY_PARAM = '_bgcc';
    private const CAPTCHA_TOKEN_FIELD = '_bgct';
    private const CAPTCHA_ANSWER_FIELD = '_bgca';
    private const CAPTCHA_TTL_SECONDS = 300;
    private const CAPTCHA_RATE_LIMIT_PREFIX = 'bot_guard.captcha_rate.';
    private const CAPTCHA_BASE_DELAY_SECONDS = 5;
    private const CAPTCHA_MAX_DELAY_SECONDS = 900;
    private const CAPTCHA_STATE_TTL_SECONDS = 7200;
    private const RATE_LIMIT_PREFIX = 'bot_guard.rate.';
    private const REQUEST_ATTR_COMPLETE_JS_CHALLENGE = '_bot_guard_complete_js_challenge';

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
    /**
     * @var string
     */
    private $appSecret;

    public function __construct(
        BotGuardDecider $decider,
        EntityManagerInterface $em,
        Connection $connection,
        string $appSecret,
        ?CacheInterface $cache = null
    )
    {
        $this->decider = $decider;
        $this->em = $em;
        $this->connection = $connection;
        $this->appSecret = $appSecret;
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

        if ($this->isRateLimitExceeded($request)) {
            $event->setResponse(new Response('', Response::HTTP_TOO_MANY_REQUESTS));

            return;
        }

        if ($this->isCaptchaAttempt($request)) {
            $event->setResponse($this->handleCaptchaAttempt($request));

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
                $event->setResponse($this->createChallengeResponse($request));

                return;
            }
        }

        if ($this->isUnderAttackEnabled() && 'js_challenge_not_passed' === (string) ($decision['reason'] ?? '')) {
            $event->setResponse($this->createCaptchaChallengeResponse($request, false));

            return;
        }

        if (!$decision['blocked']) {
            if ($this->decider->canCompleteJsChallenge($request)) {
                $request->attributes->set(self::REQUEST_ATTR_COMPLETE_JS_CHALLENGE, true);
            } elseif (!$this->hasValidAccessCookie($request) && !$this->isUnderAttackEnabled()) {
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

        if ($this->isUnderAttackEnabled()) {
            try {
                return $this->decider->hasValidJsChallengeCookie($request);
            } catch (\Throwable $e) {
                return false;
            }
        }

        return true;
    }

    private function createChallengeResponse(Request $request): Response
    {
        if ($this->isUnderAttackEnabled()) {
            return $this->createJsChallengeResponse($request);
        }

        $response = new Response('', Response::HTTP_FOUND, [
            'Location' => $this->buildChallengeTarget($request),
        ]);
        $response->headers->setCookie($this->createAccessCookie($request));

        return $response;
    }

    private function createJsChallengeResponse(Request $request): Response
    {
        $target = htmlspecialchars($this->buildChallengeTarget($request), ENT_QUOTES, 'UTF-8');
        $html = '<!doctype html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Проверка безопасности</title></head><body><noscript>Для продолжения включите JavaScript и обновите страницу.</noscript><script>(function(){window.setTimeout(function(){window.location.replace("'.$target.'");},1200);}());</script></body></html>';
        $response = new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
        $response->headers->setCookie($this->createChallengeCookie($request));

        return $response;
    }

    private function createCaptchaChallengeResponse(Request $request, bool $showError, string $customError = ''): Response
    {
        $action = htmlspecialchars($this->buildCaptchaTarget($request), ENT_QUOTES, 'UTF-8');
        $challenge = $this->buildCaptchaChallenge($request);
        $question = htmlspecialchars($challenge['question'], ENT_QUOTES, 'UTF-8');
        $token = htmlspecialchars($challenge['token'], ENT_QUOTES, 'UTF-8');
        $errorText = '' !== trim($customError) ? $customError : 'Не удалось пройти CAPTCHA. Попробуйте еще раз.';
        $errorHtml = $showError ? '<p style="color:#b50000;margin:12px 0 0;">'.htmlspecialchars($errorText, ENT_QUOTES, 'UTF-8').'</p>' : '';
        $html = '<!doctype html><html lang="ru"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Проверка безопасности</title></head><body style="font-family:Arial,sans-serif;margin:24px;"><h1 style="font-size:20px;margin:0 0 12px;">Проверка безопасности</h1><p style="margin:0 0 16px;">Обнаружена повышенная активность. Подтвердите, что вы не робот.</p><form method="post" action="'.$action.'"><label style="display:block;margin:0 0 8px;">'.$question.'</label><input type="text" name="'.self::CAPTCHA_ANSWER_FIELD.'" autocomplete="off" required style="padding:8px;min-width:220px;"><input type="hidden" name="'.self::CAPTCHA_TOKEN_FIELD.'" value="'.$token.'"><button type="submit" style="margin-top:16px;padding:10px 16px;cursor:pointer;">Продолжить</button></form>'.$errorHtml.'</body></html>';
        $response = new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);

        return $response;
    }

    /**
     * @param array{blocked: bool, challenge: bool, reason: ?string, ruleName: ?string, rulePattern: ?string, statusCode: int} $decision
     */
    private function shouldSkipChallengeByReferrer(Request $request, array $decision): bool
    {
        if ('cookie_required' !== (string) ($decision['reason'] ?? null)) {
            return false;
        }

        if ('1' === (string) $request->query->get(BotGuardDecider::CHALLENGE_QUERY_PARAM, '')) {
            return false;
        }

        try {
            return $this->decider->shouldSkipChallengeByReferrer($request);
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

    private function buildCaptchaTarget(Request $request): string
    {
        $query = $request->query->all();
        $query[BotGuardDecider::CHALLENGE_QUERY_PARAM] = '1';
        $query[self::CAPTCHA_QUERY_PARAM] = '1';
        $queryString = http_build_query($query);

        return $request->getPathInfo().('' !== $queryString ? '?'.$queryString : '');
    }

    private function isCaptchaAttempt(Request $request): bool
    {
        return $this->isUnderAttackEnabled()
            && $request->isMethod(Request::METHOD_POST)
            && '1' === (string) $request->query->get(self::CAPTCHA_QUERY_PARAM, '');
    }

    private function handleCaptchaAttempt(Request $request): Response
    {
        $rateState = $this->getCaptchaRateState($request);
        $waitSeconds = (int) ceil((float) ($rateState['lockUntil'] - microtime(true)));
        if ($waitSeconds > 0) {
            $response = $this->createCaptchaChallengeResponse(
                $request,
                true,
                sprintf('Слишком много попыток. Подождите %d сек. и попробуйте снова.', $waitSeconds)
            );
            $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) $waitSeconds);

            return $response;
        }

        if (!$this->validateCaptchaResponse($request)) {
            $wait = $this->registerCaptchaFailedAttempt($request);
            if ($wait > 0) {
                $response = $this->createCaptchaChallengeResponse(
                    $request,
                    true,
                    sprintf('Неверный ответ. Новая попытка через %d сек.', $wait)
                );
                $response->setStatusCode(Response::HTTP_TOO_MANY_REQUESTS);
                $response->headers->set('Retry-After', (string) $wait);

                return $response;
            }

            return $this->createCaptchaChallengeResponse($request, true);
        }

        $this->resetCaptchaRateState($request);
        $response = $this->createChallengeCleanupResponse($request);
        $response->headers->setCookie($this->createAccessCookie($request));
        $response->headers->setCookie($this->createJsChallengeCookie($request));
        $response->headers->clearCookie(BotGuardDecider::CHALLENGE_COOKIE_NAME, '/');

        return $response;
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

        if (null === $this->cache) {
            return false;
        }

        $config = $this->decider->getRateLimitConfig();
        $key = self::RATE_LIMIT_PREFIX.hash('sha256', $this->buildIpFingerprint((string) $request->getClientIp()));
        $window = (int) $config['windowSeconds'];
        $maxRequests = (int) $config['maxRequests'];
        $now = time();

        try {
            $state = $this->cache->get($key, function (ItemInterface $item) use ($window, $now): array {
                $item->expiresAfter($window);

                return ['count' => 0, 'startedAt' => $now];
            });

            if (!is_array($state)) {
                $state = ['count' => 0, 'startedAt' => $now];
            }

            if ((int) ($state['startedAt'] ?? 0) + $window < $now) {
                $state = ['count' => 0, 'startedAt' => $now];
            }

            $state['count'] = (int) ($state['count'] ?? 0) + 1;
            $this->cache->delete($key);
            $this->cache->get($key, function (ItemInterface $item) use ($state, $window): array {
                $item->expiresAfter($window);

                return $state;
            });

            return (int) $state['count'] > $maxRequests;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function validateCaptchaResponse(Request $request): bool
    {
        $token = trim((string) $request->request->get(self::CAPTCHA_TOKEN_FIELD, ''));
        $answer = trim((string) $request->request->get(self::CAPTCHA_ANSWER_FIELD, ''));
        if ('' === $token || '' === $answer) {
            return false;
        }

        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if ('' === $payload || '' === $signature) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $this->appSecret);
        if (!hash_equals($expectedSignature, $signature)) {
            return false;
        }

        $decoded = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        if (!is_array($decoded)) {
            return false;
        }

        $exp = isset($decoded['exp']) ? (int) $decoded['exp'] : 0;
        if ($exp < time()) {
            return false;
        }

        $ipHash = isset($decoded['ip']) ? (string) $decoded['ip'] : '';
        $uaHash = isset($decoded['ua']) ? (string) $decoded['ua'] : '';
        $salt = isset($decoded['salt']) ? (string) $decoded['salt'] : '';
        $answerHash = isset($decoded['answer']) ? (string) $decoded['answer'] : '';
        if ('' === $ipHash || '' === $uaHash || '' === $salt || '' === $answerHash) {
            return false;
        }

        $actualIpHash = hash('sha256', $this->buildIpFingerprint((string) $request->getClientIp()));
        if (!hash_equals($ipHash, $actualIpHash)) {
            return false;
        }

        $actualUaHash = hash('sha256', mb_strtolower(trim((string) $request->headers->get('User-Agent', ''))));
        if (!hash_equals($uaHash, $actualUaHash)) {
            return false;
        }

        $normalizedAnswer = mb_strtolower(preg_replace('/\s+/', '', $answer) ?? '');
        if ('' === $normalizedAnswer) {
            return false;
        }

        $actualAnswerHash = hash_hmac('sha256', $normalizedAnswer.'|'.$salt, $this->appSecret);

        return hash_equals($answerHash, $actualAnswerHash);
    }

    /**
     * @return array{question: string, token: string}
     */
    private function buildCaptchaChallenge(Request $request): array
    {
        $left = random_int(11, 49);
        $right = random_int(3, 21);
        $isPlus = 1 === random_int(0, 1);
        if (!$isPlus && $right > $left) {
            $tmp = $right;
            $right = $left;
            $left = $tmp;
        }

        $answer = $isPlus ? (string) ($left + $right) : (string) ($left - $right);
        $question = sprintf('Решите пример: %d %s %d = ?', $left, $isPlus ? '+' : '-', $right);
        $salt = bin2hex(random_bytes(8));
        $payload = [
            'exp' => time() + self::CAPTCHA_TTL_SECONDS,
            'ip' => hash('sha256', $this->buildIpFingerprint((string) $request->getClientIp())),
            'ua' => hash('sha256', mb_strtolower(trim((string) $request->headers->get('User-Agent', '')))),
            'salt' => $salt,
            'answer' => hash_hmac('sha256', $answer.'|'.$salt, $this->appSecret),
        ];
        $encoded = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, $this->appSecret);

        try {
            return [
                'question' => $question,
                'token' => $encoded.'.'.$signature,
            ];
        } catch (\Throwable $e) {
            return [
                'question' => 'Решите пример: 3 + 4 = ?',
                'token' => '',
            ];
        }
    }

    private function buildIpFingerprint(string $ip): string
    {
        $ip = trim($ip);
        if ('' === $ip) {
            return 'no-ip';
        }

        if (false !== strpos($ip, ':')) {
            $parts = explode(':', $ip);

            return implode(':', array_slice($parts, 0, 4));
        }

        $parts = explode('.', $ip);
        if (4 !== count($parts)) {
            return $ip;
        }

        return $parts[0].'.'.$parts[1].'.'.$parts[2].'.0';
    }

    /**
     * @return array{fails:int,lockUntil:float}
     */
    private function getCaptchaRateState(Request $request): array
    {
        if (null === $this->cache) {
            return ['fails' => 0, 'lockUntil' => 0.0];
        }

        $key = self::CAPTCHA_RATE_LIMIT_PREFIX.hash('sha256', $this->buildIpFingerprint((string) $request->getClientIp()));

        try {
            $state = $this->cache->get($key, function (ItemInterface $item): array {
                $item->expiresAfter(self::CAPTCHA_STATE_TTL_SECONDS);

                return ['fails' => 0, 'lockUntil' => 0.0];
            });
        } catch (\Throwable $e) {
            return ['fails' => 0, 'lockUntil' => 0.0];
        }

        if (!is_array($state)) {
            return ['fails' => 0, 'lockUntil' => 0.0];
        }

        return [
            'fails' => isset($state['fails']) ? (int) $state['fails'] : 0,
            'lockUntil' => isset($state['lockUntil']) ? (float) $state['lockUntil'] : 0.0,
        ];
    }

    private function registerCaptchaFailedAttempt(Request $request): int
    {
        if (null === $this->cache) {
            return 0;
        }

        $state = $this->getCaptchaRateState($request);
        $fails = max(0, (int) $state['fails']) + 1;
        $delay = min(self::CAPTCHA_MAX_DELAY_SECONDS, self::CAPTCHA_BASE_DELAY_SECONDS * (2 ** ($fails - 1)));
        $lockUntil = microtime(true) + $delay;
        $this->saveCaptchaRateState($request, $fails, $lockUntil);

        return (int) $delay;
    }

    private function resetCaptchaRateState(Request $request): void
    {
        if (null === $this->cache) {
            return;
        }

        $this->saveCaptchaRateState($request, 0, 0.0);
    }

    private function saveCaptchaRateState(Request $request, int $fails, float $lockUntil): void
    {
        if (null === $this->cache) {
            return;
        }

        $key = self::CAPTCHA_RATE_LIMIT_PREFIX.hash('sha256', $this->buildIpFingerprint((string) $request->getClientIp()));

        try {
            $this->cache->delete($key);
            $this->cache->get($key, function (ItemInterface $item) use ($fails, $lockUntil): array {
                $item->expiresAfter(self::CAPTCHA_STATE_TTL_SECONDS);

                return [
                    'fails' => max(0, $fails),
                    'lockUntil' => max(0.0, $lockUntil),
                ];
            });
        } catch (\Throwable $e) {
            // При ошибке кэша не блокируем легитимный трафик.
        }
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

    private function createChallengeCookie(Request $request): Cookie
    {
        return Cookie::create(
            BotGuardDecider::CHALLENGE_COOKIE_NAME,
            $this->decider->buildChallengeCookieValue($request),
            new \DateTimeImmutable('+600 seconds'),
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

