<?php

declare(strict_types=1);

namespace App\BotGuard;

use Symfony\Component\HttpFoundation\Request;

final class BotGuardRateLimiter
{
    public const RETRY_AFTER_ATTRIBUTE = '_bot_guard_retry_after';
    public const UNAVAILABLE_ATTRIBUTE = '_bot_guard_rate_unavailable';
    private const MAX_SHARD_BYTES = 1048576;
    private const MAX_SHARD_ITEMS = 2048;
    private $stateDirectory;

    public function __construct(string $stateDirectory)
    {
        $this->stateDirectory = $stateDirectory;
    }

    public function isExceeded(Request $request, string $scope, int $maxRequests, int $windowSeconds, bool $useSubnet): bool
    {
        if ($maxRequests < 1 || $windowSeconds < 1) {
            return false;
        }

        $fingerprint = $useSubnet
            ? BotGuardIpFingerprint::build((string) $request->getClientIp())
            : BotGuardIpFingerprint::normalize((string) $request->getClientIp());

        return $this->consume($request, $scope, $fingerprint, $maxRequests, $windowSeconds);
    }

    /** Atomic counters in 256 shards: bounded inode use, no cache-key restrictions. */
    public function consume(Request $request, string $scope, string $fingerprint, int $maxRequests, int $windowSeconds): bool
    {
        $key = hash('sha256', $scope."\n".$fingerprint);
        $handle = null;

        try {
            if (!is_dir($this->stateDirectory) && !@mkdir($this->stateDirectory, 0700, true) && !is_dir($this->stateDirectory)) {
                throw new \RuntimeException('Cannot create rate-limit directory.');
            }
            $handle = @fopen($this->stateDirectory.'/'.substr($key, 0, 2).'.json', 'c+');
            if (false === $handle) {
                throw new \RuntimeException('Cannot open rate-limit state.');
            }
            $deadline = microtime(true) + 0.025;
            while (!flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Rate-limit lock is busy.');
                }
                usleep(1000);
            }
            $raw = stream_get_contents($handle, self::MAX_SHARD_BYTES + 1);
            $states = '' === $raw ? [] : json_decode((string) $raw, true);
            if (!is_array($states) || strlen((string) $raw) > self::MAX_SHARD_BYTES) {
                throw new \RuntimeException('Invalid rate-limit state.');
            }
            $now = time();
            foreach ($states as $id => $state) {
                if (!is_array($state) || (int) ($state['until'] ?? 0) <= $now) {
                    unset($states[$id]);
                }
            }
            if (!isset($states[$key])) {
                if (count($states) >= self::MAX_SHARD_ITEMS) {
                    throw new \RuntimeException('Rate-limit shard is full.');
                }
                $states[$key] = ['count' => 0, 'until' => $now + $windowSeconds];
            }
            $states[$key]['count'] = min($maxRequests + 1, (int) $states[$key]['count'] + 1);
            $exceeded = $states[$key]['count'] > $maxRequests;
            if ($exceeded) {
                $request->attributes->set(self::RETRY_AFTER_ATTRIBUTE, max(1, $states[$key]['until'] - $now));
            }
            $encoded = json_encode($states);
            if (false === $encoded || strlen($encoded) > self::MAX_SHARD_BYTES) {
                throw new \RuntimeException('Rate-limit state is too large.');
            }
            rewind($handle);
            if (strlen($encoded) !== fwrite($handle, $encoded) || !ftruncate($handle, strlen($encoded)) || !fflush($handle)) {
                throw new \RuntimeException('Cannot persist rate-limit state.');
            }

            return $exceeded;
        } catch (\Throwable $e) {
            $request->attributes->set(self::UNAVAILABLE_ATTRIBUTE, true);
            $request->attributes->set(self::RETRY_AFTER_ATTRIBUTE, 10);

            return true;
        } finally {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }
}
