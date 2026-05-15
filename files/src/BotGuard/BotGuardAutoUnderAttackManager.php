<?php

declare(strict_types=1);

namespace App\BotGuard;

use App\Entity\BotGuard\BotGuardSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class BotGuardAutoUnderAttackManager
{
    private const STATE_CACHE_KEY = 'bot_guard.auto_under_attack.state';
    private const SETTINGS_CACHE_KEY = 'bot_guard.settings.v1';

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var CacheInterface|null
     */
    private $cache;

    public function __construct(EntityManagerInterface $em, ?CacheInterface $cache = null)
    {
        $this->em = $em;
        $this->cache = $cache;
    }

    public function evaluate(?float $cpuPercent, ?float $memUsedPercent): void
    {
        /** @var BotGuardSettings|null $settings */
        $settings = $this->em->getRepository(BotGuardSettings::class)->findOneBy([], ['id' => 'ASC']);

        if (!$settings instanceof BotGuardSettings || !$settings->isAutoUnderAttackEnabled()) {
            return;
        }

        $cpu = null !== $cpuPercent ? (float) $cpuPercent : 0.0;
        $mem = null !== $memUsedPercent ? (float) $memUsedPercent : 0.0;
        $highThresholdCpu = (float) $settings->getAutoUnderAttackCpuPercent();
        $highThresholdMem = (float) $settings->getAutoUnderAttackMemPercent();
        $releaseThreshold = (float) $settings->getAutoUnderAttackReleasePercent();
        $requiredSamples = max(1, $settings->getAutoUnderAttackDurationMinutes());

        $isHigh = $cpu >= $highThresholdCpu || $mem >= $highThresholdMem;
        $isLow = $cpu < $releaseThreshold && $mem < $releaseThreshold;

        $state = $this->loadState();
        if ($isHigh) {
            $state['highSamples'] = (int) ($state['highSamples'] ?? 0) + 1;
            $state['lowSamples'] = 0;
        } elseif ($isLow) {
            $state['lowSamples'] = (int) ($state['lowSamples'] ?? 0) + 1;
            $state['highSamples'] = 0;
        } else {
            $state['lowSamples'] = 0;
        }

        $changed = false;

        if (!$settings->isUnderAttack() && (int) $state['highSamples'] >= $requiredSamples) {
            $settings->setUnderAttack(true);
            $state['activatedByAuto'] = true;
            $changed = true;
        }

        if ($settings->isUnderAttack()
            && !empty($state['activatedByAuto'])
            && (int) $state['lowSamples'] >= $requiredSamples
        ) {
            $settings->setUnderAttack(false);
            $state['activatedByAuto'] = false;
            $changed = true;
        }

        $this->saveState($state);

        if ($changed) {
            $this->em->flush($settings);
            $this->invalidateSettingsCache();
        }
    }

    /**
     * @return array{highSamples:int,lowSamples:int,activatedByAuto:bool}
     */
    private function loadState(): array
    {
        $defaults = ['highSamples' => 0, 'lowSamples' => 0, 'activatedByAuto' => false];

        if (null === $this->cache) {
            return $defaults;
        }

        try {
            return $this->cache->get(self::STATE_CACHE_KEY, function (ItemInterface $item) use ($defaults): array {
                $item->expiresAfter(7200);

                return $defaults;
            });
        } catch (\Throwable $e) {
            return $defaults;
        }
    }

    /**
     * @param array{highSamples:int,lowSamples:int,activatedByAuto:bool} $state
     */
    private function saveState(array $state): void
    {
        if (null === $this->cache) {
            return;
        }

        try {
            $this->cache->delete(self::STATE_CACHE_KEY);
            $this->cache->get(self::STATE_CACHE_KEY, function (ItemInterface $item) use ($state): array {
                $item->expiresAfter(7200);

                return $state;
            });
        } catch (\Throwable $e) {
            // ignore cache errors
        }
    }

    private function invalidateSettingsCache(): void
    {
        if (null === $this->cache) {
            return;
        }

        try {
            $this->cache->delete(self::SETTINGS_CACHE_KEY);
        } catch (\Throwable $e) {
            // ignore cache errors
        }
    }
}
