<?php

declare(strict_types=1);

namespace App\Twig;

use App\BotGuard\BotGuardStatsProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class BotGuardExtension extends AbstractExtension
{
    /**
     * @var BotGuardStatsProvider
     */
    private $statsProvider;

    public function __construct(BotGuardStatsProvider $statsProvider)
    {
        $this->statsProvider = $statsProvider;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('bot_guard_stats', [$this, 'getStats']),
        ];
    }

    /**
     * @return array{
     *   total:int,
     *   today:int,
     *   last24h:int,
     *   last7d:int,
     *   topRule:array{name:string,count:int}|null,
     *   topIp:array{ip:string,count:int}|null,
     *   daily:array<int,array{day:string,count:int}>
     * }
     */
    public function getStats(int $days = 14): array
    {
        return $this->statsProvider->getDashboardStats($days);
    }
}

