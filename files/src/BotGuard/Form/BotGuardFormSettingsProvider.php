<?php

declare(strict_types=1);

namespace App\BotGuard\Form;

use App\Entity\BotGuard\BotGuardFormSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class BotGuardFormSettingsProvider
{
    public const CACHE_KEY = 'bot_guard.form_settings.v1';

    private const CACHE_TTL_SECONDS = 15;

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
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $defaults = $this->getDefaults();

        if (null === $this->cache) {
            return $this->loadFromDatabase($defaults);
        }

        try {
            return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) use ($defaults): array {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);

                return $this->loadFromDatabase($defaults);
            });
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    public function isEnabled(): bool
    {
        return !empty($this->getData()['enabled']);
    }

    public function invalidate(): void
    {
        if (null === $this->cache) {
            return;
        }

        try {
            $this->cache->delete(self::CACHE_KEY);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getDefaults(): array
    {
        return [
            'enabled' => false,
            'protectCheckout' => false,
            'minFillSeconds' => 3,
            'minConfirmDelayMs' => 400,
            'rateLimitEnabled' => true,
            'rateLimitMaxRequests' => 10,
            'rateLimitWindowSeconds' => 3600,
            'blockedNames' => '',
            'blockedEmails' => '',
            'loggingEnabled' => true,
            'checkHoneypot' => true,
        ];
    }

    /**
     * @param array<string, mixed> $defaults
     *
     * @return array<string, mixed>
     */
    private function loadFromDatabase(array $defaults): array
    {
        /** @var BotGuardFormSettings|null $settings */
        $settings = $this->em->getRepository(BotGuardFormSettings::class)->findOneBy([], ['id' => 'ASC']);

        if (!$settings instanceof BotGuardFormSettings) {
            return $defaults;
        }

        return [
            'enabled' => $settings->isEnabled(),
            'protectCheckout' => $settings->isProtectCheckout(),
            'minFillSeconds' => $settings->getMinFillSeconds(),
            'minConfirmDelayMs' => $settings->getMinConfirmDelayMs(),
            'rateLimitEnabled' => $settings->isRateLimitEnabled(),
            'rateLimitMaxRequests' => $settings->getRateLimitMaxRequests(),
            'rateLimitWindowSeconds' => $settings->getRateLimitWindowSeconds(),
            'blockedNames' => (string) $settings->getBlockedNames(),
            'blockedEmails' => (string) $settings->getBlockedEmails(),
            'loggingEnabled' => $settings->isLoggingEnabled(),
            'checkHoneypot' => $settings->isCheckHoneypot(),
        ];
    }
}
