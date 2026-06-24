<?php

declare(strict_types=1);

namespace App\BotGuard;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class BotGuardRateLimiter
{
    private const CACHE_PREFIX = 'bot_guard.rate.';

    /**
     * @var CacheInterface|null
     */
    private $cache;

    public function __construct(?CacheInterface $cache = null)
    {
        $this->cache = $cache;
    }

    public function isExceeded(Request $request, string $scope, int $maxRequests, int $windowSeconds, bool $useSubnet): bool
    {
        if (null === $this->cache || $maxRequests < 1 || $windowSeconds < 1) {
            return false;
        }

        $fingerprint = $useSubnet
            ? BotGuardIpFingerprint::build((string) $request->getClientIp())
            : (string) $request->getClientIp();

        $key = self::CACHE_PREFIX.$scope.'.'.hash('sha256', $fingerprint);
        $now = time();

        try {
            $state = $this->cache->get($key, function (ItemInterface $item) use ($windowSeconds, $now): array {
                $item->expiresAfter($windowSeconds);

                return ['count' => 0, 'startedAt' => $now];
            });

            if (!is_array($state)) {
                $state = ['count' => 0, 'startedAt' => $now];
            }

            if ((int) ($state['startedAt'] ?? 0) + $windowSeconds < $now) {
                $state = ['count' => 0, 'startedAt' => $now];
            }

            $state['count'] = (int) ($state['count'] ?? 0) + 1;
            $this->cache->delete($key);
            $this->cache->get($key, function (ItemInterface $item) use ($state, $windowSeconds): array {
                $item->expiresAfter($windowSeconds);

                return $state;
            });

            return (int) $state['count'] > $maxRequests;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
