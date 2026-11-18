<?php

declare(strict_types=1);

namespace App\BotGuard;

use Symfony\Component\HttpFoundation\Request;

/** Machine endpoints retain their own token, IP and payment verification. */
final class BotGuardRequestPolicy
{
    private $integrationPrefixes;
    private $postPaths;

    public function __construct(array $integrationPrefixes = [], array $postPaths = [])
    {
        $this->integrationPrefixes = $integrationPrefixes;
        $this->postPaths = $postPaths;
    }

    public function isIntegration(Request $request): bool
    {
        $path = $request->getPathInfo();
        foreach ($this->integrationPrefixes as $prefix) {
            $prefix = rtrim((string) $prefix, '/');
            if ('' !== $prefix && '/' === $prefix[0] && ($path === $prefix || 0 === strpos($path, $prefix.'/'))) {
                return true;
            }
        }

        return $request->isMethod('POST') && in_array($path, $this->postPaths, true);
    }

    public static function isSeoFile(Request $request): bool
    {
        $path = $request->getPathInfo();

        return in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && ('/robots.txt' === $path || 1 === preg_match('#^/sitemap(?:[\w.-]*\.xml|\.xml/[^/]+)$#D', $path));
    }
}
