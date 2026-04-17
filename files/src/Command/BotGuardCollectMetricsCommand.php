<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class BotGuardCollectMetricsCommand extends Command
{
    /**
     * @var Connection
     */
    private $connection;

    public function __construct(Connection $connection)
    {
        parent::__construct();
        $this->connection = $connection;
    }

    public static function getDefaultName(): ?string
    {
        return 'app:bot-guard:collect-metrics';
    }

    public static function getDefaultDescription(): string
    {
        return 'Collect current server load and memory metrics for Bot Guard monitoring dashboard.';
    }

    protected function configure(): void
    {
        $this->setDescription(self::getDefaultDescription());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $metrics = $this->collectMetrics();

        $this->connection->insert('bot_guard_system_metric', [
            'sampled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'load_1' => $metrics['load1'],
            'load_5' => $metrics['load5'],
            'load_15' => $metrics['load15'],
            'mem_total_mb' => $metrics['memTotalMb'],
            'mem_used_mb' => $metrics['memUsedMb'],
            'mem_used_percent' => $metrics['memUsedPercent'],
            'source' => $metrics['source'],
        ]);

        $io->success(sprintf(
            'Bot Guard metrics saved. load1=%s, load5=%s, load15=%s, memUsed=%s%%, source=%s',
            $this->formatFloat($metrics['load1'], 3),
            $this->formatFloat($metrics['load5'], 3),
            $this->formatFloat($metrics['load15'], 3),
            $this->formatFloat($metrics['memUsedPercent'], 2),
            $metrics['source']
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array{
     *   load1:?float,
     *   load5:?float,
     *   load15:?float,
     *   memTotalMb:?float,
     *   memUsedMb:?float,
     *   memUsedPercent:?float,
     *   source:string
     * }
     */
    private function collectMetrics(): array
    {
        $load = $this->readLoadAverage();
        $memory = $this->readMemoryUsage();

        return [
            'load1' => $load['load1'],
            'load5' => $load['load5'],
            'load15' => $load['load15'],
            'memTotalMb' => $memory['totalMb'],
            'memUsedMb' => $memory['usedMb'],
            'memUsedPercent' => $memory['usedPercent'],
            'source' => $memory['source'].'+'.$load['source'],
        ];
    }

    /**
     * @return array{load1:?float,load5:?float,load15:?float,source:string}
     */
    private function readLoadAverage(): array
    {
        if (!function_exists('sys_getloadavg')) {
            return ['load1' => null, 'load5' => null, 'load15' => null, 'source' => 'load-unavailable'];
        }

        $load = sys_getloadavg();

        if (!is_array($load) || count($load) < 3) {
            return ['load1' => null, 'load5' => null, 'load15' => null, 'source' => 'load-unavailable'];
        }

        return [
            'load1' => round((float) $load[0], 3),
            'load5' => round((float) $load[1], 3),
            'load15' => round((float) $load[2], 3),
            'source' => 'sys_getloadavg',
        ];
    }

    /**
     * @return array{totalMb:?float,usedMb:?float,usedPercent:?float,source:string}
     */
    private function readMemoryUsage(): array
    {
        $meminfoPath = '/proc/meminfo';

        if (!is_readable($meminfoPath)) {
            return ['totalMb' => null, 'usedMb' => null, 'usedPercent' => null, 'source' => 'memory-unavailable'];
        }

        $content = file_get_contents($meminfoPath);

        if (false === $content || '' === trim($content)) {
            return ['totalMb' => null, 'usedMb' => null, 'usedPercent' => null, 'source' => 'memory-unavailable'];
        }

        $rows = preg_split('/\R+/', trim($content)) ?: [];
        $values = [];

        foreach ($rows as $row) {
            if (1 === preg_match('/^([A-Za-z_]+):\s+(\d+)/', $row, $matches)) {
                $values[$matches[1]] = (float) $matches[2];
            }
        }

        if (!isset($values['MemTotal'])) {
            return ['totalMb' => null, 'usedMb' => null, 'usedPercent' => null, 'source' => 'memory-unavailable'];
        }

        $totalKb = $values['MemTotal'];
        $availableKb = $values['MemAvailable'] ?? (($values['MemFree'] ?? 0.0) + ($values['Buffers'] ?? 0.0) + ($values['Cached'] ?? 0.0));
        $usedKb = max(0.0, $totalKb - $availableKb);
        $usedPercent = $totalKb > 0 ? round(($usedKb / $totalKb) * 100, 2) : null;

        return [
            'totalMb' => round($totalKb / 1024, 2),
            'usedMb' => round($usedKb / 1024, 2),
            'usedPercent' => $usedPercent,
            'source' => 'proc_meminfo',
        ];
    }

    private function formatFloat(?float $value, int $precision): string
    {
        if (null === $value) {
            return 'n/a';
        }

        return number_format($value, $precision, '.', '');
    }
}
