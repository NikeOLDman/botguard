<?php

declare(strict_types=1);

namespace App\Twig;

use App\BotGuard\BotGuardStatsProvider;
use App\Entity\BotGuard\BotGuardSettings;
use Doctrine\ORM\EntityManagerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class BotGuardExtension extends AbstractExtension
{
    /**
     * @var BotGuardStatsProvider
     */
    private $statsProvider;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    public function __construct(BotGuardStatsProvider $statsProvider, EntityManagerInterface $em)
    {
        $this->statsProvider = $statsProvider;
        $this->em = $em;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('bot_guard_stats', [$this, 'getStats']),
            new TwigFunction('bot_guard_settings', [$this, 'getSettings']),
        ];
    }

    public function getSettings(): ?BotGuardSettings
    {
        /** @var BotGuardSettings|null $settings */
        $settings = $this->em->getRepository(BotGuardSettings::class)->findOneBy([], ['id' => 'ASC']);

        return $settings;
    }

    /**
     * @return array{
     *   total:int,
     *   today:int,
     *   last24h:int,
     *   last7d:int,
     *   topRule:array{name:string,count:int}|null,
     *   topRules:array<int,array{name:string,count:int}>,
     *   topIp:array{ip:string,count:int}|null,
     *   topIps:array<int,array{ip:string,count:int}>,
     *   daily:array<int,array{day:string,count:int}>
     *   suspiciousLast24h:int,
     *   suspiciousTop:array<int,array{ip:string,userAgent:string,reason:string,count:int,lastSeen:string}>,
     *   systemCurrent:array{available:bool,load1:?float,load5:?float,load15:?float,cpuCores:?int,cpuPercent:?float,memUsedPercent:?float,sampledAt:?string},
     *   systemRecent:array<int,array{time:string,load1:float,memUsedPercent:float}>,
     *   systemDaily:array<int,array{day:string,load1:?float,cpuPercent:?float,memUsedPercent:?float}>,
     *   peaksLastHour:array{hasData:bool,maxLoad1:?float,maxCpuPercent:?float,maxMemUsedPercent:?float,blocked:int,suspicious:int,cpuLevel:string,memLevel:string}
     * }
     */
    public function getStats(int $days = 14): array
    {
        return $this->statsProvider->getDashboardStats($days);
    }
}

