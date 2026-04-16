<?php

declare(strict_types=1);

namespace App\BotGuard;

use Doctrine\DBAL\Connection;

class BotGuardStatsProvider
{
    /**
     * @var Connection
     */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
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
    public function getDashboardStats(int $days = 14): array
    {
        $days = max(3, min(90, $days));

        return [
            'total' => $this->safeInt('SELECT COUNT(*) FROM bot_guard_log'),
            'today' => $this->safeInt('SELECT COUNT(*) FROM bot_guard_log WHERE DATE(blocked_at) = CURDATE()'),
            'last24h' => $this->safeInt('SELECT COUNT(*) FROM bot_guard_log WHERE blocked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'),
            'last7d' => $this->safeInt('SELECT COUNT(*) FROM bot_guard_log WHERE blocked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'),
            'topRule' => $this->safeTopRule(),
            'topIp' => $this->safeTopIp(),
            'daily' => $this->safeDaily($days),
        ];
    }

    private function fetchInt(string $sql): int
    {
        return (int) $this->connection->fetchOne($sql);
    }

    private function safeInt(string $sql): int
    {
        try {
            return $this->fetchInt($sql);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @return array{name:string,count:int}|null
     */
    private function fetchTopRule(): ?array
    {
        $row = $this->connection->fetchAssociative("
            SELECT COALESCE(rule_name, reason) AS top_name, COUNT(*) AS cnt
            FROM bot_guard_log
            GROUP BY COALESCE(rule_name, reason)
            ORDER BY cnt DESC
            LIMIT 1
        ");

        if (!$row) {
            return null;
        }

        return [
            'name' => (string) $row['top_name'],
            'count' => (int) $row['cnt'],
        ];
    }

    /**
     * @return array{name:string,count:int}|null
     */
    private function safeTopRule(): ?array
    {
        try {
            return $this->fetchTopRule();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{ip:string,count:int}|null
     */
    private function fetchTopIp(): ?array
    {
        $row = $this->connection->fetchAssociative("
            SELECT ip, COUNT(*) AS cnt
            FROM bot_guard_log
            WHERE ip IS NOT NULL AND ip <> ''
            GROUP BY ip
            ORDER BY cnt DESC
            LIMIT 1
        ");

        if (!$row) {
            return null;
        }

        return [
            'ip' => (string) $row['ip'],
            'count' => (int) $row['cnt'],
        ];
    }

    /**
     * @return array{ip:string,count:int}|null
     */
    private function safeTopIp(): ?array
    {
        try {
            return $this->fetchTopIp();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<int,array{day:string,count:int}>
     */
    private function fetchDaily(int $days): array
    {
        $dbToday = $this->fetchDbToday();
        $rows = $this->connection->fetchAllAssociative(sprintf("
            SELECT DATE(blocked_at) AS day, COUNT(*) AS cnt
            FROM bot_guard_log
            WHERE blocked_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
              AND blocked_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
            GROUP BY day
            ORDER BY day ASC
        ", $days));

        $indexed = [];

        foreach ($rows as $row) {
            $indexed[(string) $row['day']] = (int) $row['cnt'];
        }

        $out = [];
        $end = new \DateTimeImmutable($dbToday);
        $date = $end->modify('-'.($days - 1).' days');

        while ($date <= $end) {
            $key = $date->format('Y-m-d');
            $out[] = [
                'day' => $key,
                'count' => $indexed[$key] ?? 0,
            ];
            $date = $date->modify('+1 day');
        }

        return $out;
    }

    private function fetchDbToday(): string
    {
        $today = $this->connection->fetchOne("SELECT DATE_FORMAT(CURDATE(), '%Y-%m-%d')");

        if (!is_string($today) || '' === trim($today)) {
            return (new \DateTimeImmutable('today'))->format('Y-m-d');
        }

        return $today;
    }

    /**
     * @return array<int,array{day:string,count:int}>
     */
    private function safeDaily(int $days): array
    {
        try {
            return $this->fetchDaily($days);
        } catch (\Throwable $e) {
            $out = [];
            $end = new \DateTimeImmutable((new \DateTimeImmutable('today'))->format('Y-m-d'));
            $date = $end->modify('-'.($days - 1).' days');

            while ($date <= $end) {
                $out[] = [
                    'day' => $date->format('Y-m-d'),
                    'count' => 0,
                ];
                $date = $date->modify('+1 day');
            }

            return $out;
        }
    }
}

