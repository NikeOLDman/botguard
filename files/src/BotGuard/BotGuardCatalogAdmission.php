<?php

declare(strict_types=1);

namespace App\BotGuard;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Separate capacity for crawlers, admitted browsers and new clients. No waiting. */
final class BotGuardCatalogAdmission
{
    private $decider;
    private $limiter;
    private $directory;
    private $enabled;
    private $budgets;
    private $slots;
    private $allowedSorts;

    public function __construct(BotGuardDecider $decider, BotGuardRateLimiter $limiter, string $directory, bool $enabled, array $budgets, array $slots, array $allowedSorts = ['', 'discount_price', 'o.position', 'o.id', 'translations.title', 'o.updatedAt'])
    {
        $this->decider = $decider;
        $this->limiter = $limiter;
        $this->directory = $directory;
        $this->enabled = $enabled;
        $this->budgets = $budgets;
        $this->slots = $slots;
        $this->allowedSorts = $allowedSorts;
    }

    public function run(Request $request, ?string $expression, callable $render): Response
    {
        if (!$this->enabled) {
            return $render();
        }
        if (!self::validInput($request, $expression, $this->allowedSorts)) {
            return new Response('Некорректные параметры каталога.', 400, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }
        $group = $this->decider->isRequestWhitelisted($request) ? 'crawler' : ($this->decider->hasValidAccessCookie($request) ? 'browser' : 'anonymous');
        if ($this->limiter->consume($request, 'catalog_budget', $group, (int) $this->budgets[$group], 60)) {
            return BotGuardResponse::rateLimited($request);
        }

        $lease = $this->acquire($group, (int) $this->slots[$group]);
        if (null === $lease) {
            $request->attributes->set(BotGuardRateLimiter::UNAVAILABLE_ATTRIBUTE, true);
            $request->attributes->set(BotGuardRateLimiter::RETRY_AFTER_ATTRIBUTE, 5);

            return BotGuardResponse::rateLimited($request);
        }
        try {
            return $render();
        } finally {
            flock($lease, LOCK_UN);
            fclose($lease);
        }
    }

    public static function validInput(Request $request, ?string $expression, array $allowedSorts = ['', 'discount_price', 'o.position', 'o.id', 'translations.title', 'o.updatedAt']): bool
    {
        if (strlen($request->getRequestUri()) > 8192 || strlen((string) $expression) > 8192) {
            return false;
        }
        $parts = '' === (string) $expression ? [] : explode('/', trim((string) $expression, '/'));
        if (count($parts) > 32 || substr_count((string) $expression, '_') > 512) {
            return false;
        }
        $query = $request->query->all();
        $page = $query['page'] ?? '1';
        // page=0 is the shared "Посмотреть все" link. Other non-positive values stay rejected.
        if (!is_scalar($page) || !ctype_digit((string) $page) || (int) $page > 10000) {
            return false;
        }
        $sort = $query['sort'] ?? null;
        $direction = $query['direction'] ?? null;
        if (null !== $sort && !in_array($sort, $allowedSorts, true)) {
            return false;
        }
        if (null !== $direction && (!is_string($direction) || !in_array(strtolower($direction), ['', 'asc', 'desc'], true))) {
            return false;
        }

        return true;
    }

    private function acquire(string $group, int $slots)
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return null;
        }
        $slots = max(1, min(16, $slots));
        $offset = random_int(0, $slots - 1);
        for ($i = 0; $i < $slots; ++$i) {
            $handle = @fopen($this->directory.'/'.$group.'-'.(($offset + $i) % $slots).'.lock', 'c');
            if (false === $handle) {
                continue;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            fclose($handle);
        }

        return null;
    }
}
