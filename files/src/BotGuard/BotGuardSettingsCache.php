<?php

declare(strict_types=1);

namespace App\BotGuard;

use Symfony\Contracts\Cache\CacheInterface;

class BotGuardSettingsCache
{
    public const CACHE_KEY = 'bot_guard.settings.v2';
    public const RULES_CACHE_KEY = 'bot_guard.rules.v2';
    public const FORM_SETTINGS_CACHE_KEY = 'bot_guard.form_settings.v1';

    /**
     * @var CacheInterface|null
     */
    private $cache;

    public function __construct(?CacheInterface $cache = null)
    {
        $this->cache = $cache;
    }

    public function invalidate(): void
    {
        if (null === $this->cache) {
            return;
        }

        try {
            $this->cache->delete(self::CACHE_KEY);
            $this->cache->delete(self::RULES_CACHE_KEY);
            $this->cache->delete(self::FORM_SETTINGS_CACHE_KEY);
        } catch (\Throwable $e) {
            // Игнорируем сбой сброса кэша — настройки подтянутся из БД при следующем запросе.
        }
    }
}
