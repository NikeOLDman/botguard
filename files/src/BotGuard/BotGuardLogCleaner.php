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
        $days = max(1, min(3650, $days));
        $threshold = (new \DateTimeImmutable())->modify('-'.$days.' days')->format('Y-m-d H:i:s');

        return $this->connection->executeStatement(
            'DELETE FROM bot_guard_log WHERE blocked_at < :threshold',
            ['threshold' => $threshold]
        );
    }
}

