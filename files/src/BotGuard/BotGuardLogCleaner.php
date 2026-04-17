<?php

declare(strict_types=1);

namespace App\BotGuard;

use Doctrine\DBAL\Connection;

class BotGuardLogCleaner
{
    /**
     * @var Connection
     */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function cleanupOlderThanDays(int $days): int
    {
        $result = $this->cleanupAllOlderThanDays($days);

        return $result['bot_guard_log'];
    }

    /**
     * @return array{bot_guard_log:int,bot_guard_suspicious_event:int,bot_guard_system_metric:int,total:int}
     */
    public function cleanupAllOlderThanDays(int $days): array
    {
        $days = max(1, min(3650, $days));
        $threshold = (new \DateTimeImmutable())->modify('-'.$days.' days')->format('Y-m-d H:i:s');
        $deletedBlocked = $this->executeStatementCompat(
            'DELETE FROM bot_guard_log WHERE blocked_at < :threshold',
            ['threshold' => $threshold]
        );
        $deletedSuspicious = $this->executeStatementCompat(
            'DELETE FROM bot_guard_suspicious_event WHERE created_at < :threshold',
            ['threshold' => $threshold]
        );
        $deletedMetrics = $this->executeStatementCompat(
            'DELETE FROM bot_guard_system_metric WHERE sampled_at < :threshold',
            ['threshold' => $threshold]
        );

        return [
            'bot_guard_log' => $deletedBlocked,
            'bot_guard_suspicious_event' => $deletedSuspicious,
            'bot_guard_system_metric' => $deletedMetrics,
            'total' => $deletedBlocked + $deletedSuspicious + $deletedMetrics,
        ];
    }

    private function executeStatementCompat(string $sql, array $params): int
    {
        if (method_exists($this->connection, 'executeStatement')) {
            return $this->connection->executeStatement($sql, $params);
        }

        return (int) $this->connection->executeUpdate($sql, $params);
    }
}

