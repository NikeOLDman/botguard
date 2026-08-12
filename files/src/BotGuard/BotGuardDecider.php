<?php

declare(strict_types=1);

namespace App\BotGuard;

use App\Entity\BotGuard\BotGuardRule;
use App\Entity\BotGuard\BotGuardSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class BotGuardDecider
{
    public const ACCESS_COOKIE_NAME = 'bot_guard_access';
    public const JS_COOKIE_NAME = 'bot_guard_js';
    public const CHALLENGE_COOKIE_NAME = 'bot_guard_ch';
    public const CHALLENGE_QUERY_PARAM = '_bgc';

    private const CACHE_TTL_SECONDS = 15;
    private const SETTINGS_CACHE_KEY = 'bot_guard.settings.v2';
    private const RULES_CACHE_KEY = 'bot_guard.rules.v2';

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var CacheInterface|null
     */
    private $cache;

    /**
     * @var string
     */
    private $appSecret;

    /**
     * @var BotGuardReferrerMatcher
     */
    private $referrerMatcher;

    /**
     * @var BotGuardRateLimiter
     */
    private $rateLimiter;

    /**
     * @var BotGuardCatalogFilterPageRegistry
     */
    private $catalogFilterPages;

    public function __construct(
        EntityManagerInterface $em,
        string $appSecret,
        BotGuardReferrerMatcher $referrerMatcher,
        BotGuardRateLimiter $rateLimiter,
        BotGuardCatalogFilterPageRegistry $catalogFilterPages,
        ?CacheInterface $cache = null
    ) {
        $this->em = $em;
        $this->appSecret = $appSecret;
        $this->referrerMatcher = $referrerMatcher;
        $this->rateLimiter = $rateLimiter;
        $this->catalogFilterPages = $catalogFilterPages;
        $this->cache = $cache;
    }

    /**
     * @return array{blocked: bool, challenge: bool, reason: ?string, ruleName: ?string, rulePattern: ?string, statusCode: int}
     */
    public function decide(Request $request): array
    {
        $settings = $this->getSettingsData();
        $statusCode = (int) $settings['statusCode'];
        $userAgent = (string) $request->headers->get('User-Agent', '');
        $ip = (string) $request->getClientIp();
        $uri = (string) $request->getPathInfo();

        if (empty($settings['enabled'])) {
            return $this->allow($statusCode);
        }

        if ($this->isWhitelistedForCookieCheck($settings, $userAgent)) {
            return $this->allow($statusCode);
        }

        if (!empty($settings['blockEmptyUserAgent']) && '' === trim($userAgent)) {
            return $this->deny('empty_user_agent', null, null, $statusCode);
        }

        if (!empty($settings['underAttack'])) {
            // JS challenge on /filtered breaks the product filter; soft cookie check is enough.
            if (false !== stripos($uri, '/filtered')) {
                return $this->decideSoftCookieProtection($request, $statusCode);
            }

            $underAttackDecision = $this->decideStrictProtection($request, 'js_challenge_required', $statusCode);

            if (null !== $underAttackDecision) {
                return $underAttackDecision;
            }

            return $this->allow($statusCode);
        }

        $softCookieRulesMatched = false;
        $strictCookieRulesMatched = false;
        $rules = $this->getRulesData();

        foreach ($rules as $rule) {
            if (BotGuardRule::TYPE_COOKIE_STRICT === $rule['type']) {
                if ($this->matchesCookieRule($rule, $userAgent, $uri)) {
                    $strictCookieRulesMatched = true;
                }
                continue;
            }

            if (BotGuardRule::TYPE_COOKIE_REQUIRED === $rule['type']) {
                if ($this->matchesCookieRule($rule, $userAgent, $uri)) {
                    $softCookieRulesMatched = true;
                }
                continue;
            }

            if ($this->matchesRule($rule, $userAgent, $ip, $uri)) {
                return $this->deny('rule_match', $rule['name'], $rule['pattern'], $statusCode);
            }
        }

        if ($strictCookieRulesMatched) {
            if ($this->shouldUseSoftCheckForPath((string) $request->getPathInfo())) {
                return $this->decideSoftCookieProtection($request, $statusCode);
            }

            $strictDecision = $this->decideStrictProtection($request, 'cookie_strict', $statusCode);

            if (null !== $strictDecision) {
                return $strictDecision;
            }

            return $this->allow($statusCode);
        }

        if ($softCookieRulesMatched) {
            return $this->decideSoftCookieProtection($request, $statusCode);
        }

        return $this->allow($statusCode);
    }

    public function isLoggingEnabled(): bool
    {
        $settings = $this->getSettingsData();

        if (!empty($settings['loggingEnabled']) && !empty($settings['underAttack']) && !empty($settings['reduceLoggingUnderAttack'])) {
            return false;
        }

        return !empty($settings['loggingEnabled']);
    }

    /**
     * @param array<string, mixed>|null $settings
     */
    public function isRateLimitEnabled(array $settings = null): bool
    {
        $settings = $settings ?? $this->getSettingsData();

        return !empty($settings['underAttack']) && !empty($settings['rateLimitEnabled']);
    }

    /**
     * @param array<string, mixed>|null $settings
     *
     * @return array{maxRequests:int,windowSeconds:int}
     */
    public function getRateLimitConfig(array $settings = null): array
    {
        $settings = $settings ?? $this->getSettingsData();

        return [
            'maxRequests' => max(1, (int) ($settings['rateLimitMaxRequests'] ?? 60)),
            'windowSeconds' => max(10, (int) ($settings['rateLimitWindowSeconds'] ?? 60)),
        ];
    }

    public function isPathRateLimitExceeded(Request $request): bool
    {
        $settings = $this->getSettingsData();

        if (empty($settings['pathRateLimitEnabled'])) {
            return false;
        }

        $pattern = trim((string) ($settings['pathRateLimitUriPattern'] ?? ''));
        if ('' === $pattern || false === stripos((string) $request->getPathInfo(), $pattern)) {
            return false;
        }

        if ($this->isUserAgentWhitelisted((string) $request->headers->get('User-Agent', ''))) {
            return false;
        }

        return $this->rateLimiter->isExceeded(
            $request,
            'path:'.md5($pattern),
            (int) $settings['pathRateLimitMaxRequests'],
            (int) $settings['pathRateLimitWindowSeconds'],
            true
        );
    }

    public function canCompleteJsChallenge(Request $request): bool
    {
        return $this->isChallengeRetry($request)
            && $this->hasValidJsChallengeCookie($request)
            && $this->hasValidAccessCookie($request);
    }

    public function isUserAgentWhitelisted(string $userAgent): bool
    {
        return $this->isWhitelistedByRawList((string) $this->getSettingsData()['cookieWhitelistUas'], $userAgent);
    }

    public function shouldSkipChallengeByReferrer(Request $request, string $challengeReason): bool
    {
        if ('cookie_required' !== $challengeReason) {
            return false;
        }

        $settings = $this->getSettingsData();
        $trustReferrer = !empty($settings['trustReferrer']);

        if (
            !$trustReferrer
            && !empty($settings['catalogFilterPagesSoftCheck'])
            && $this->catalogFilterPages->isCatalogFilterPagePath((string) $request->getPathInfo())
        ) {
            $trustReferrer = true;
        }

        return $this->referrerMatcher->isTrusted(
            $request,
            $trustReferrer,
            (string) ($settings['trustedReferrerDomains'] ?? '')
        );
    }

    public function isUnderAttackEnabled(): bool
    {
        return !empty($this->getSettingsData()['underAttack']);
    }

    public function isStrictChallengeReason(string $reason): bool
    {
        return in_array($reason, ['cookie_strict', 'js_challenge_required'], true);
    }

    public function getJsChallengeMinDelayMs(): int
    {
        return max(500, (int) ($this->getSettingsData()['jsChallengeMinDelayMs'] ?? 1200));
    }

    public function shouldIssueGlobalAccessCookie(Request $request): bool
    {
        if ($this->isUnderAttackEnabled()) {
            return false;
        }

        if ($this->hasValidAccessCookie($request)) {
            return false;
        }

        return !$this->uriMatchesStrictCookieRule((string) $request->getPathInfo());
    }

    public function hasValidAccessCookie(Request $request): bool
    {
        $raw = trim((string) $request->cookies->get(self::ACCESS_COOKIE_NAME, ''));
        if ('' === $raw) {
            return false;
        }

        [$payload, $signature] = array_pad(explode('.', $raw, 2), 2, '');
        if ('' === $payload || '' === $signature) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $this->appSecret);
        if (!hash_equals($expected, $signature)) {
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

        return $this->isSignedPayloadValid($request, $decoded, 'access');
    }

    public function hasValidJsChallengeCookie(Request $request): bool
    {
        return $this->hasValidSignedCookie($request, self::JS_COOKIE_NAME, 'js');
    }

    public function hasValidChallengeCookie(Request $request): bool
    {
        return $this->hasValidSignedCookie($request, self::CHALLENGE_COOKIE_NAME, 'challenge');
    }

    public function buildAccessCookieValue(Request $request): string
    {
        return $this->buildSignedCookieValue($request, 'access', 3600 * 6);
    }

    public function buildJsCookieValue(Request $request): string
    {
        return $this->buildSignedCookieValue($request, 'js', 900);
    }

    public function buildChallengeCookieValue(Request $request): string
    {
        return $this->buildSignedCookieValue($request, 'challenge', 600);
    }

    /**
     * @return array{blocked: bool, challenge: bool, reason: ?string, ruleName: ?string, rulePattern: ?string, statusCode: int}|null
     */
    private function decideStrictProtection(Request $request, string $reason, int $statusCode): ?array
    {
        if ($this->hasValidAccessCookie($request) && $this->hasValidJsChallengeCookie($request)) {
            return null;
        }

        if ($this->canCompleteJsChallenge($request)) {
            return $this->allow($statusCode);
        }

        if ($this->isChallengeRetry($request)) {
            return $this->deny('js_challenge_not_passed', null, null, $statusCode);
        }

        return $this->challenge($reason, $statusCode);
    }

    /**
     * @return array{blocked: bool, challenge: bool, reason: ?string, ruleName: ?string, rulePattern: ?string, statusCode: int}
     */
    private function decideSoftCookieProtection(Request $request, int $statusCode): array
    {
        if ($this->hasValidAccessCookie($request)) {
            return $this->allow($statusCode);
        }

        if ($this->isChallengeRetry($request)) {
            return $this->deny('cookie_not_set', null, null, $statusCode);
        }

        return $this->challenge('cookie_required', $statusCode);
    }

    public function shouldUseSoftCheckForPath(string $pathInfo): bool
    {
        if (empty($this->getSettingsData()['catalogFilterPagesSoftCheck'])) {
            return false;
        }

        // Dynamic filter URLs (/catalog/filtered/...) must use soft cookie check too —
        // strict JS challenge returns HTML without .l-main and breaks AJAX filter UI.
        if (false !== stripos($pathInfo, '/filtered')) {
            return true;
        }

        return $this->catalogFilterPages->isCatalogFilterPagePath($pathInfo);
    }

    public function uriMatchesStrictCookieRule(string $uri): bool
    {
        if ($this->shouldUseSoftCheckForPath($uri)) {
            return false;
        }

        foreach ($this->getRulesData() as $rule) {
            if (BotGuardRule::TYPE_COOKIE_STRICT !== $rule['type']) {
                continue;
            }

            if ($this->matchesCookieRule($rule, '', $uri)) {
                return true;
            }
        }

        return false;
    }

    private function buildSignedCookieValue(Request $request, string $type, int $ttlSeconds): string
    {
        $expiresAt = time() + $ttlSeconds;
        $payload = [
            'v' => 2,
            't' => $type,
            'exp' => $expiresAt,
            'ua' => hash('sha256', mb_strtolower(trim((string) $request->headers->get('User-Agent', '')))),
            'ip' => hash('sha256', BotGuardIpFingerprint::build((string) $request->getClientIp())),
            'rnd' => bin2hex(random_bytes(8)),
        ];
        $encoded = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, $this->appSecret);

        return $encoded.'.'.$signature;
    }

    private function hasValidSignedCookie(Request $request, string $cookieName, string $expectedType): bool
    {
        $raw = trim((string) $request->cookies->get($cookieName, ''));
        if ('' === $raw) {
            return false;
        }

        [$payload, $signature] = array_pad(explode('.', $raw, 2), 2, '');
        if ('' === $payload || '' === $signature) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $this->appSecret);
        if (!hash_equals($expected, $signature)) {
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

        return $this->isSignedPayloadValid($request, $decoded, $expectedType);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function isSignedPayloadValid(Request $request, array $decoded, ?string $expectedType): bool
    {
        if (null !== $expectedType) {
            $type = isset($decoded['t']) ? (string) $decoded['t'] : '';
            if ($type !== $expectedType) {
                return false;
            }
        }

        $uaHash = isset($decoded['ua']) ? (string) $decoded['ua'] : '';
        $ipHash = isset($decoded['ip']) ? (string) $decoded['ip'] : '';
        if ('' === $uaHash || '' === $ipHash) {
            return false;
        }

        $actualUaHash = hash('sha256', mb_strtolower(trim((string) $request->headers->get('User-Agent', ''))));
        if (!hash_equals($uaHash, $actualUaHash)) {
            return false;
        }

        $actualIpHash = hash('sha256', BotGuardIpFingerprint::build((string) $request->getClientIp()));
        if (!hash_equals($ipHash, $actualIpHash)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function getSettingsData(): array
    {
        $defaults = [
            'enabled' => true,
            'blockEmptyUserAgent' => true,
            'loggingEnabled' => true,
            'underAttack' => false,
            'trustReferrer' => false,
            'trustedReferrerDomains' => '',
            'cookieWhitelistUas' => '',
            'statusCode' => 403,
            'rateLimitEnabled' => true,
            'rateLimitMaxRequests' => 60,
            'rateLimitWindowSeconds' => 60,
            'pathRateLimitEnabled' => true,
            'pathRateLimitUriPattern' => '/filtered',
            'pathRateLimitMaxRequests' => 30,
            'pathRateLimitWindowSeconds' => 60,
            'jsChallengeMinDelayMs' => 1200,
            'catalogFilterPagesSoftCheck' => true,
            'reduceLoggingUnderAttack' => true,
            'autoUnderAttackEnabled' => false,
            'autoUnderAttackCpuPercent' => 95,
            'autoUnderAttackMemPercent' => 95,
            'autoUnderAttackDurationMinutes' => 3,
            'autoUnderAttackReleasePercent' => 75,
        ];

        if (null === $this->cache) {
            return $this->loadSettingsDataFromDatabase($defaults);
        }

        try {
            return $this->cache->get(self::SETTINGS_CACHE_KEY, function (ItemInterface $item) use ($defaults): array {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);

                return $this->loadSettingsDataFromDatabase($defaults);
            });
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    /**
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    private function loadSettingsDataFromDatabase(array $defaults): array
    {
        /** @var BotGuardSettings|null $settings */
        $settings = $this->em->getRepository(BotGuardSettings::class)->findOneBy([], ['id' => 'ASC']);

        if (!$settings instanceof BotGuardSettings) {
            return $defaults;
        }

        return [
            'enabled' => $settings->isEnabled(),
            'blockEmptyUserAgent' => $settings->isBlockEmptyUserAgent(),
            'loggingEnabled' => $settings->isLoggingEnabled(),
            'underAttack' => $settings->isUnderAttack(),
            'trustReferrer' => $settings->isTrustReferrer(),
            'trustedReferrerDomains' => (string) $settings->getTrustedReferrerDomains(),
            'cookieWhitelistUas' => (string) $settings->getCookieWhitelistUserAgents(),
            'statusCode' => $settings->getBlockStatusCode(),
            'rateLimitEnabled' => $settings->isRateLimitEnabled(),
            'rateLimitMaxRequests' => $settings->getRateLimitMaxRequests(),
            'rateLimitWindowSeconds' => $settings->getRateLimitWindowSeconds(),
            'pathRateLimitEnabled' => $settings->isPathRateLimitEnabled(),
            'pathRateLimitUriPattern' => $settings->getPathRateLimitUriPattern(),
            'pathRateLimitMaxRequests' => $settings->getPathRateLimitMaxRequests(),
            'pathRateLimitWindowSeconds' => $settings->getPathRateLimitWindowSeconds(),
            'jsChallengeMinDelayMs' => $settings->getJsChallengeMinDelayMs(),
            'catalogFilterPagesSoftCheck' => $settings->isCatalogFilterPagesSoftCheck(),
            'reduceLoggingUnderAttack' => $settings->isReduceLoggingUnderAttack(),
            'autoUnderAttackEnabled' => $settings->isAutoUnderAttackEnabled(),
            'autoUnderAttackCpuPercent' => $settings->getAutoUnderAttackCpuPercent(),
            'autoUnderAttackMemPercent' => $settings->getAutoUnderAttackMemPercent(),
            'autoUnderAttackDurationMinutes' => $settings->getAutoUnderAttackDurationMinutes(),
            'autoUnderAttackReleasePercent' => $settings->getAutoUnderAttackReleasePercent(),
        ];
    }

    /**
     * @return array<int,array{name: string, type: string, pattern: string, uriPattern: ?string}>
     */
    private function getRulesData(): array
    {
        if (null === $this->cache) {
            return $this->loadRulesDataFromDatabase();
        }

        try {
            return $this->cache->get(self::RULES_CACHE_KEY, function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);

                return $this->loadRulesDataFromDatabase();
            });
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<int,array{name: string, type: string, pattern: string, uriPattern: ?string}>
     */
    private function loadRulesDataFromDatabase(): array
    {
        /** @var BotGuardRule[] $rules */
        $rules = $this->em->getRepository(BotGuardRule::class)->findBy(['active' => true], ['priority' => 'ASC', 'id' => 'ASC']);
        $out = [];

        foreach ($rules as $rule) {
            $out[] = [
                'name' => $rule->getName(),
                'type' => $rule->getType(),
                'pattern' => $rule->getPattern(),
                'uriPattern' => $rule->getUriPattern(),
            ];
        }

        return $out;
    }

    /**
     * @param array{name: string, type: string, pattern: string, uriPattern: ?string} $rule
     */
    private function matchesRule(array $rule, string $userAgent, string $ip, string $uri): bool
    {
        $type = $rule['type'];
        $pattern = trim($rule['pattern']);
        $uriPattern = trim((string) $rule['uriPattern']);

        if ('' === $pattern) {
            return false;
        }

        if (BotGuardRule::TYPE_USER_AGENT_CONTAINS === $type) {
            return false !== stripos($userAgent, $pattern) && $this->matchesUriScope($uriPattern, $uri);
        }

        if (BotGuardRule::TYPE_USER_AGENT_REGEX === $type) {
            set_error_handler(static function (): bool {
                return true;
            });

            try {
                return 1 === preg_match($pattern, $userAgent) && $this->matchesUriScope($uriPattern, $uri);
            } finally {
                restore_error_handler();
            }
        }

        if (BotGuardRule::TYPE_IP_EXACT === $type) {
            return $ip !== '' && $ip === $pattern && $this->matchesUriScope($uriPattern, $uri);
        }

        if (BotGuardRule::TYPE_URI_CONTAINS === $type) {
            return false !== stripos($uri, $pattern);
        }

        return false;
    }

    /**
     * @param array{name: string, type: string, pattern: string, uriPattern: ?string} $rule
     */
    private function matchesCookieRule(array $rule, string $userAgent, string $uri): bool
    {
        $pathPattern = trim($rule['pattern']);
        $userAgentPattern = trim((string) $rule['uriPattern']);

        if ('' === $pathPattern) {
            return false;
        }

        if (false === stripos($uri, $pathPattern)) {
            return false;
        }

        if ('' === $userAgentPattern) {
            return true;
        }

        return false !== stripos($userAgent, $userAgentPattern);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function isWhitelistedForCookieCheck(array $settings, string $userAgent): bool
    {
        if ('' === trim($userAgent)) {
            return false;
        }

        return $this->isWhitelistedByRawList((string) $settings['cookieWhitelistUas'], $userAgent);
    }

    private function isWhitelistedByRawList(string $raw, string $userAgent): bool
    {
        if ('' === trim($raw) || '' === trim($userAgent)) {
            return false;
        }

        $items = preg_split('/[\r\n,]+/', $raw) ?: [];

        foreach ($items as $item) {
            $item = trim($item);
            if ('' === $item) {
                continue;
            }

            if (false !== mb_stripos($userAgent, $item)) {
                return true;
            }
        }

        return false;
    }

    private function isChallengeRetry(Request $request): bool
    {
        return '1' === (string) $request->query->get(self::CHALLENGE_QUERY_PARAM, '');
    }

    private function matchesUriScope(string $uriPattern, string $uri): bool
    {
        if ('' === $uriPattern) {
            return true;
        }

        return false !== stripos($uri, $uriPattern);
    }

    /**
     * @return array{blocked: bool, challenge: bool, reason: ?string, ruleName: ?string, rulePattern: ?string, statusCode: int}
     */
    private function allow(int $statusCode): array
    {
        return [
            'blocked' => false,
            'challenge' => false,
            'reason' => null,
            'ruleName' => null,
            'rulePattern' => null,
            'statusCode' => $statusCode,
        ];
    }

    /**
     * @return array{blocked: bool, challenge: bool, reason: ?string, ruleName: ?string, rulePattern: ?string, statusCode: int}
     */
    private function deny(string $reason, ?string $ruleName, ?string $rulePattern, int $statusCode): array
    {
        return [
            'blocked' => true,
            'challenge' => false,
            'reason' => $reason,
            'ruleName' => $ruleName,
            'rulePattern' => $rulePattern,
            'statusCode' => $statusCode,
        ];
    }

    /**
     * @return array{blocked: bool, challenge: bool, reason: ?string, ruleName: ?string, rulePattern: ?string, statusCode: int}
     */
    private function challenge(string $reason, int $statusCode): array
    {
        return [
            'blocked' => false,
            'challenge' => true,
            'reason' => $reason,
            'ruleName' => null,
            'rulePattern' => null,
            'statusCode' => $statusCode,
        ];
    }
}
