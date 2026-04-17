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
    public const CHALLENGE_QUERY_PARAM = '_bgc';

    private const CACHE_TTL_SECONDS = 15;
    private const SETTINGS_CACHE_KEY = 'bot_guard.settings.v1';
    private const RULES_CACHE_KEY = 'bot_guard.rules.v1';

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var CacheInterface|null
     */
    private $cache;

    public function __construct(EntityManagerInterface $em, ?CacheInterface $cache = null)
    {
        $this->em = $em;
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

        if (!empty($settings['blockEmptyUserAgent']) && '' === trim($userAgent)) {
            return $this->deny('empty_user_agent', null, null, $statusCode);
        }

        $cookieRulesMatched = [];
        $rules = $this->getRulesData();
        foreach ($rules as $rule) {
            if (BotGuardRule::TYPE_COOKIE_REQUIRED === $rule['type']) {
                if ($this->matchesCookieRule($rule, $userAgent, $uri)) {
                    $cookieRulesMatched[] = $rule;
                }
                continue;
            }

            if ($this->matchesRule($rule, $userAgent, $ip, $uri)) {
                return $this->deny('rule_match', $rule['name'], $rule['pattern'], $statusCode);
            }
        }

        if ($this->requiresCookieValidation($settings, $userAgent, $cookieRulesMatched)) {
            if (!$this->hasAccessCookie($request)) {
                if ($this->isChallengeRetry($request)) {
                    return $this->deny('cookie_not_set', null, null, $statusCode);
                }

                return $this->challenge('cookie_required', $statusCode);
            }
        }

        return $this->allow($statusCode);
    }

    public function isLoggingEnabled(): bool
    {
        $settings = $this->getSettingsData();

        return !empty($settings['loggingEnabled']);
    }

    public function isUserAgentWhitelisted(string $userAgent): bool
    {
        return $this->isWhitelistedByRawList((string) $this->getSettingsData()['cookieWhitelistUas'], $userAgent);
    }

    /**
     * @return array{enabled: bool, blockEmptyUserAgent: bool, loggingEnabled: bool, underAttack: bool, cookieWhitelistUas: string, statusCode: int}
     */
    private function getSettingsData(): array
    {
        $defaults = [
            'enabled' => true,
            'blockEmptyUserAgent' => true,
            'loggingEnabled' => true,
            'underAttack' => false,
            'cookieWhitelistUas' => '',
            'statusCode' => 403,
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
     * @param array{enabled: bool, blockEmptyUserAgent: bool, loggingEnabled: bool, underAttack: bool, cookieWhitelistUas: string, statusCode: int} $defaults
     *
     * @return array{enabled: bool, blockEmptyUserAgent: bool, loggingEnabled: bool, underAttack: bool, cookieWhitelistUas: string, statusCode: int}
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
            'cookieWhitelistUas' => (string) $settings->getCookieWhitelistUserAgents(),
            'statusCode' => $settings->getBlockStatusCode(),
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
     * @param array{enabled: bool, blockEmptyUserAgent: bool, loggingEnabled: bool, underAttack: bool, cookieWhitelistUas: string, statusCode: int} $settings
     * @param array<int,array{name: string, type: string, pattern: string, uriPattern: ?string}>                                 $cookieRulesMatched
     */
    private function requiresCookieValidation(array $settings, string $userAgent, array $cookieRulesMatched): bool
    {
        if (!empty($settings['underAttack'])) {
            return true;
        }

        if ([] === $cookieRulesMatched) {
            return false;
        }

        if ($this->isWhitelistedForCookieCheck($settings, $userAgent)) {
            return false;
        }

        return true;
    }

    /**
     * @param array{enabled: bool, blockEmptyUserAgent: bool, loggingEnabled: bool, underAttack: bool, cookieWhitelistUas: string, statusCode: int} $settings
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

    private function hasAccessCookie(Request $request): bool
    {
        return '' !== trim((string) $request->cookies->get(self::ACCESS_COOKIE_NAME, ''));
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

