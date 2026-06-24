<?php

declare(strict_types=1);

namespace App\BotGuard;

use Symfony\Component\HttpFoundation\Request;

final class BotGuardReferrerMatcher
{
    private const DEFAULT_TRUSTED_DOMAINS = [
        'yandex.ru',
        'ya.ru',
        'yandex.com',
        'google.com',
        'google.ru',
        'vk.com',
        'vk.ru',
    ];

    public function isTrusted(Request $request, bool $trustReferrerEnabled, string $allowedDomainsRaw): bool
    {
        if (!$trustReferrerEnabled) {
            return false;
        }

        $referrer = trim((string) $request->headers->get('referer', ''));
        if ('' === $referrer) {
            return false;
        }

        $referrerHost = parse_url($referrer, PHP_URL_HOST);
        if (!is_string($referrerHost) || '' === trim($referrerHost)) {
            return false;
        }

        $referrerHost = mb_strtolower($referrerHost);
        $siteHost = mb_strtolower($request->getHost());
        if ($referrerHost === $siteHost) {
            return false;
        }

        foreach ($this->parseDomains($allowedDomainsRaw) as $domain) {
            if ($this->hostMatchesDomain($referrerHost, $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function parseDomains(string $raw): array
    {
        $items = preg_split('/[\r\n,]+/', $raw) ?: [];
        $domains = [];

        foreach ($items as $item) {
            $item = mb_strtolower(trim($item));
            if ('' === $item) {
                continue;
            }
            $domains[] = ltrim($item, '.');
        }

        if ([] === $domains) {
            return self::DEFAULT_TRUSTED_DOMAINS;
        }

        return $domains;
    }

    private function hostMatchesDomain(string $host, string $domain): bool
    {
        if ($host === $domain) {
            return true;
        }

        $suffix = '.'.$domain;

        return strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix;
    }
}
