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
     * @return array{blocked: bool, reason: ?string, ruleName: ?string, rulePattern: ?string, statusCode: int}
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

        $rules = $this->getRulesData();
        foreach ($rules as $rule) {
            if ($this->matchesRule($rule, $userAgent, $ip, $uri)) {
                return $this->deny('rule_match', $rule['name'], $rule['pattern'], $statusCode);
            }
        }

        return $this->allow($statusCode);
    }

    public function isLoggingEnabled(): bool
    {
        $settings = $this->getSettingsData();

        return !empty($settings['loggingEnabled']);
    }

    /**
     * @return array{enabled: bool, blockEmptyUserAgent: bool, loggingEnabled: bool, statusCode: int}
     */
    private function getSettingsData(): array
    {
        $defaults = [
            'enabled' => true,
            'blockEmptyUserAgent' => true,
            'loggingEnabled' => true,
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
     * @param array{enabled: bool, blockEmptyUserAgent: bool, loggingEnabled: bool, statusCode: int} $defaults
     *
     * @return array{enabled: bool, blockEmptyUserAgent: bool, loggingEnabled: bool, statusCode: int}
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

    private function matchesUriScope(string $uriPattern, string $uri): bool
    {
        if ('' === $uriPattern) {
            return true;
        }

        return false !== stripos($uri, $uriPattern);
    }

    /**
     * @return array{blocked: bool, reason: ?string, ruleName: ?string, rulePattern: ?string, statusCode: int}
     */
    private function allow(int $statusCode): array
    {
        return [
            'blocked' => false,
            'reason' => null,
            'ruleName' => null,
            'rulePattern' => null,
            'statusCode' => $statusCode,
        ];
    }

    /**
     * @return array{blocked: bool, reason: ?string, ruleName: ?string, rulePattern: ?string, statusCode: int}
     */
    private function deny(string $reason, ?string $ruleName, ?string $rulePattern, int $statusCode): array
    {
        return [
            'blocked' => true,
            'reason' => $reason,
            'ruleName' => $ruleName,
            'rulePattern' => $rulePattern,
            'statusCode' => $statusCode,
        ];
    }
}

