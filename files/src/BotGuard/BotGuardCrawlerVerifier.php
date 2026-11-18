<?php

declare(strict_types=1);

namespace App\BotGuard;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Forward-confirmed reverse DNS, including search, advertising and analytics bots. */
final class BotGuardCrawlerVerifier
{
    private $cache;
    private $resolver;
    private $rateLimiter;

    public function __construct(CacheInterface $cache, BotGuardDnsResolver $resolver, BotGuardRateLimiter $rateLimiter)
    {
        $this->cache = $cache;
        $this->resolver = $resolver;
        $this->rateLimiter = $rateLimiter;
    }

    public function isVerified(Request $request, string $additionalUserAgents = ''): bool
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }
        if ($request->attributes->has('_bot_guard_verified_crawler')) {
            return (bool) $request->attributes->get('_bot_guard_verified_crawler');
        }
        $provider = $this->provider((string) $request->headers->get('User-Agent', ''), $additionalUserAgents);
        $ip = BotGuardIpFingerprint::normalize((string) $request->getClientIp());
        if (null === $provider || '' === $ip || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $request->attributes->set('_bot_guard_verified_crawler', false);
            return false;
        }

        $key = 'crawler.v1.'.hash('sha256', $provider."\n".$ip);
        try {
            $result = $this->cache->get($key, function (ItemInterface $item, bool &$save) use ($request, $provider, $ip, $key): array {
                $probe = new Request();
                // Unknown spoofed IPs cannot create unbounded subprocess/DNS work.
                $result = [];
                $background = 'cli' === PHP_SAPI && $request->attributes->get('_bot_guard_background_verification');
                if ($background || !$this->rateLimiter->consume($probe, 'crawler_dns', 'all', 30, 60)) {
                    $result = $this->resolver->resolve($ip);
                }
                if ([] === $result) {
                    $save = false;
                    $stale = $this->cache->get($key.'.last_good', function (ItemInterface $old, bool &$save): bool {
                        $save = false;

                        return false;
                    });
                    $item->expiresAfter(15);

                    return ['verified' => (bool) $stale, 'pending' => !$stale];
                }

                $host = strtolower(rtrim((string) ($result['host'] ?? ''), '.'));
                $addresses = array_map([BotGuardIpFingerprint::class, 'normalize'], (array) ($result['ips'] ?? []));
                $valid = $this->matchesHost($provider, $host) && in_array($ip, $addresses, true);
                $item->expiresAfter($valid ? 86400 : 300);
                if ($valid) {
                    $this->cache->delete($key.'.last_good');
                    $this->cache->get($key.'.last_good', function (ItemInterface $old): bool {
                        $old->expiresAfter(604800);

                        return true;
                    });
                }

                return ['verified' => $valid, 'pending' => false];
            });
        } catch (\Throwable $error) {
            $result = ['verified' => false, 'pending' => true];
        }

        $request->attributes->set('_bot_guard_verified_crawler', !empty($result['verified']));
        $request->attributes->set('_bot_guard_crawler_pending', !empty($result['pending']));

        return !empty($result['verified']);
    }

    private function provider(string $ua, string $additionalUserAgents): ?string
    {
        if (preg_match('/(?:Googlebot|Google-InspectionTool|Google-Site-Verification|GoogleOther|AdsBot-Google|Mediapartners-Google|Storebot-Google|Google-Read-Aloud|Google-Safety)/i', $ua)) {
            return 'google';
        }
        if (preg_match('/(?:Yandex[A-Za-z]+|YaDirectFetcher|YaComBot)/i', $ua)) {
            return 'yandex';
        }
        foreach (preg_split('/[\r\n,]+/', $additionalUserAgents) ?: [] as $candidate) {
            $candidate = trim($candidate);
            if ('' !== $candidate && false !== stripos($ua, $candidate)) {
                return 'configured';
            }
        }

        return null;
    }

    private function matchesHost(string $provider, string $host): bool
    {
        if ('configured' === $provider) {
            return $this->matchesHost('yandex', $host) || $this->matchesHost('google', $host);
        }
        if ('yandex' === $provider) {
            return 1 === preg_match('/\.(?:yandex\.ru|yandex\.net|yandex\.com)$/D', $host);
        }

        // Generic Google Cloud customer hosts do not identify a Google crawler.
        return 1 === preg_match('/^(?:crawl-[a-z0-9-]+\.googlebot\.com|geo-crawl-[a-z0-9-]+\.geo\.googlebot\.com|rate-limited-proxy-[a-z0-9-]+\.google\.com|google-proxy-[a-z0-9-]+\.google\.com)$/D', $host);
    }
}
