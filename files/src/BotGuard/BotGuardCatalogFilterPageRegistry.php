<?php

declare(strict_types=1);

namespace App\BotGuard;

use Darvin\ECommerceBundle\Entity\CatalogFilteredInterface;
use Darvin\ECommerceBundle\Router\CatalogFilteredRouterInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class BotGuardCatalogFilterPageRegistry
{
    public const CACHE_KEY = 'bot_guard.catalog_filter_pages.v1';

    private const CACHE_TTL_SECONDS = 300;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var CatalogFilteredRouterInterface
     */
    private $catalogFilteredRouter;

    /**
     * @var CacheInterface|null
     */
    private $cache;

    public function __construct(
        EntityManagerInterface $em,
        CatalogFilteredRouterInterface $catalogFilteredRouter,
        ?CacheInterface $cache = null
    ) {
        $this->em = $em;
        $this->catalogFilteredRouter = $catalogFilteredRouter;
        $this->cache = $cache;
    }

    public function isCatalogFilterPagePath(string $pathInfo): bool
    {
        $normalized = $this->normalizePath($pathInfo);
        if ('/' === $normalized) {
            return false;
        }

        return isset($this->loadPathIndex()[$normalized]);
    }

    public function invalidate(): void
    {
        if (null === $this->cache) {
            return;
        }

        try {
            $this->cache->delete(self::CACHE_KEY);
        } catch (\Throwable $e) {
            // ignore cache errors
        }
    }

    /**
     * @return array<string, true>
     */
    private function loadPathIndex(): array
    {
        if (null === $this->cache) {
            return $this->buildPathIndex();
        }

        try {
            /** @var array<string, true> $index */
            $index = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);

                return $this->buildPathIndex();
            });

            return $index;
        } catch (\Throwable $e) {
            return $this->buildPathIndex();
        }
    }

    /**
     * @return array<string, true>
     */
    private function buildPathIndex(): array
    {
        $index = [];

        /** @var CatalogFilteredInterface[] $pages */
        $pages = $this->em->createQueryBuilder()
            ->select('cf')
            ->from(CatalogFilteredInterface::class, 'cf')
            ->innerJoin('cf.translations', 't')
            ->where('t.enabled = :enabled')
            ->setParameter('enabled', true)
            ->groupBy('cf.id')
            ->getQuery()
            ->getResult();

        foreach ($pages as $page) {
            $path = $this->resolvePagePath($page);
            if (null === $path) {
                continue;
            }

            $index[$this->normalizePath($path)] = true;
        }

        return $index;
    }

    private function resolvePagePath(CatalogFilteredInterface $page): ?string
    {
        try {
            $url = $this->catalogFilteredRouter->generateUrl($page, UrlGeneratorInterface::ABSOLUTE_PATH);
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_string($url) || '' === trim($url)) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && '' !== $path ? $path : null;
    }

    private function normalizePath(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: $path;
        $path = '/'.trim($path, '/');

        return '/' === $path ? '/' : $path.'/';
    }
}
