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
    /**
     * @var int|null
     */
    private $cpuCores;

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
    public function getDashboardStats(int $days = 14): array
    {
        $days = max(3, min(90, $days));
        $topRules = $this->safeTopRules(5);
        $topIps = $this->safeTopIps(5);

        return [
            'total' => $this->safeInt('SELECT COUNT(*) FROM bot_guard_log'),
            'today' => $this->safeInt('SELECT COUNT(*) FROM bot_guard_log WHERE DATE(blocked_at) = CURDATE()'),
            'last24h' => $this->safeInt('SELECT COUNT(*) FROM bot_guard_log WHERE blocked_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'),
            'last7d' => $this->safeInt('SELECT COUNT(*) FROM bot_guard_log WHERE blocked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'),
            'topRule' => $topRules[0] ?? null,
            'topRules' => $topRules,
            'topIp' => $topIps[0] ?? null,
            'topIps' => $topIps,
            'daily' => $this->safeDaily($days),
            'suspiciousLast24h' => $this->safeInt('SELECT COUNT(*) FROM bot_guard_suspicious_event WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'),
            'suspiciousTop' => $this->safeSuspiciousTop(10),
            'systemCurrent' => $this->safeSystemCurrent(),
            'systemRecent' => $this->safeSystemRecent(),
            'systemDaily' => $this->safeSystemDaily($days),
            'peaksLastHour' => $this->safePeaksLastHour(),
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
     * @return array<int,array{name:string,count:int}>
     */
    private function fetchTopRules(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(sprintf("
            SELECT COALESCE(rule_name, reason) AS top_name, COUNT(*) AS cnt
            FROM bot_guard_log
            GROUP BY COALESCE(rule_name, reason)
            ORDER BY cnt DESC
            LIMIT %d
        ", $limit));
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'name' => (string) $row['top_name'],
                'count' => (int) $row['cnt'],
            ];
        }

        return $out;
    }

    /**
     * @return array<int,array{name:string,count:int}>
     */
    private function safeTopRules(int $limit): array
    {
        try {
            return $this->fetchTopRules($limit);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<int,array{ip:string,count:int}>
     */
    private function fetchTopIps(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(sprintf("
            SELECT ip, COUNT(*) AS cnt
            FROM bot_guard_log
            WHERE ip IS NOT NULL AND ip <> ''
            GROUP BY ip
            ORDER BY cnt DESC
            LIMIT %d
        ", $limit));
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'ip' => (string) $row['ip'],
                'count' => (int) $row['cnt'],
            ];
        }

        return $out;
    }

    /**
     * @return array<int,array{ip:string,count:int}>
     */
    private function safeTopIps(int $limit): array
    {
        try {
            return $this->fetchTopIps($limit);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<int,array{day:string,count:int}>
     */
    private function fetchDaily(int $days): array
    {
        $dbToday = new \DateTimeImmutable($this->fetchDbToday());
        $from = $dbToday->modify('-'.($days - 1).' days')->format('Y-m-d 00:00:00');
        $to = $dbToday->modify('+1 day')->format('Y-m-d 00:00:00');
        $rows = $this->connection->fetchAllAssociative(sprintf("
            SELECT DATE(blocked_at) AS day, COUNT(*) AS cnt
            FROM bot_guard_log
            WHERE blocked_at >= '%s'
              AND blocked_at < '%s'
            GROUP BY day
            ORDER BY day ASC
        ", $from, $to));

        $indexed = [];

        foreach ($rows as $row) {
            $indexed[(string) $row['day']] = (int) $row['cnt'];
        }

        $out = [];
        $end = $dbToday;
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

    /**
     * @return array<int,array{ip:string,userAgent:string,reason:string,count:int,lastSeen:string}>
     */
    private function fetchSuspiciousTop(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(sprintf("
            SELECT
                COALESCE(ip, '') AS ip,
                COALESCE(user_agent, '') AS ua,
                COALESCE(reason, '') AS reason,
                COUNT(*) AS cnt,
                DATE_FORMAT(MAX(created_at), '%%Y-%%m-%%d %%H:%%i:%%s') AS last_seen
            FROM bot_guard_suspicious_event
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY COALESCE(ip, ''), COALESCE(user_agent, ''), COALESCE(reason, '')
            ORDER BY cnt DESC
            LIMIT %d
        ", $limit));
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'ip' => (string) $row['ip'],
                'userAgent' => (string) $row['ua'],
                'reason' => (string) $row['reason'],
                'count' => (int) $row['cnt'],
                'lastSeen' => (string) $row['last_seen'],
            ];
        }

        return $out;
    }

    /**
     * @return array<int,array{ip:string,userAgent:string,reason:string,count:int,lastSeen:string}>
     */
    private function safeSuspiciousTop(int $limit): array
    {
        try {
            return $this->fetchSuspiciousTop($limit);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array{available:bool,load1:?float,load5:?float,load15:?float,cpuCores:?int,cpuPercent:?float,memUsedPercent:?float,sampledAt:?string}
     */
    private function fetchSystemCurrent(): array
    {
        $row = $this->connection->fetchAssociative("
            SELECT
                load_1,
                load_5,
                load_15,
                mem_used_percent,
                DATE_FORMAT(sampled_at, '%Y-%m-%d %H:%i:%s') AS sampled_at_fmt
            FROM bot_guard_system_metric
            ORDER BY sampled_at DESC
            LIMIT 1
        ");

        if (!$row) {
            return [
                'available' => false,
                'load1' => null,
                'load5' => null,
                'load15' => null,
                'cpuCores' => $this->getCpuCores(),
                'cpuPercent' => null,
                'memUsedPercent' => null,
                'sampledAt' => null,
            ];
        }

        $load1 = null !== $row['load_1'] ? (float) $row['load_1'] : null;
        $cpuCores = $this->getCpuCores();

        return [
            'available' => true,
            'load1' => $load1,
            'load5' => null !== $row['load_5'] ? (float) $row['load_5'] : null,
            'load15' => null !== $row['load_15'] ? (float) $row['load_15'] : null,
            'cpuCores' => $cpuCores,
            'cpuPercent' => $this->normalizeLoadToPercent($load1, $cpuCores),
            'memUsedPercent' => null !== $row['mem_used_percent'] ? (float) $row['mem_used_percent'] : null,
            'sampledAt' => '' !== (string) $row['sampled_at_fmt'] ? (string) $row['sampled_at_fmt'] : null,
        ];
    }

    /**
     * @return array{available:bool,load1:?float,load5:?float,load15:?float,cpuCores:?int,cpuPercent:?float,memUsedPercent:?float,sampledAt:?string}
     */
    private function safeSystemCurrent(): array
    {
        try {
            return $this->fetchSystemCurrent();
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'load1' => null,
                'load5' => null,
                'load15' => null,
                'cpuCores' => $this->getCpuCores(),
                'cpuPercent' => null,
                'memUsedPercent' => null,
                'sampledAt' => null,
            ];
        }
    }

    /**
     * @return array<int,array{time:string,load1:float,memUsedPercent:float}>
     */
    private function fetchSystemRecent(): array
    {
        $rows = $this->connection->fetchAllAssociative("
            SELECT
                DATE_FORMAT(sampled_at, '%H:%i') AS sample_time,
                COALESCE(load_1, 0) AS load_1,
                COALESCE(mem_used_percent, 0) AS mem_used_percent
            FROM bot_guard_system_metric
            WHERE sampled_at >= DATE_SUB(NOW(), INTERVAL 4 HOUR)
            ORDER BY sampled_at ASC
            LIMIT 240
        ");
        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'time' => (string) $row['sample_time'],
                'load1' => (float) $row['load_1'],
                'memUsedPercent' => (float) $row['mem_used_percent'],
            ];
        }

        return $out;
    }

    /**
     * @return array<int,array{time:string,load1:float,memUsedPercent:float}>
     */
    private function safeSystemRecent(): array
    {
        try {
            return $this->fetchSystemRecent();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<int,array{day:string,load1:?float,cpuPercent:?float,memUsedPercent:?float}>
     */
    private function fetchSystemDaily(int $days): array
    {
        $cpuCores = $this->getCpuCores();
        $dbToday = new \DateTimeImmutable($this->fetchDbToday());
        $from = $dbToday->modify('-'.($days - 1).' days')->format('Y-m-d 00:00:00');
        $to = $dbToday->modify('+1 day')->format('Y-m-d 00:00:00');
        $rows = $this->connection->fetchAllAssociative(sprintf("
            SELECT
                DATE(sampled_at) AS day,
                AVG(load_1) AS avg_load_1,
                AVG(mem_used_percent) AS avg_mem_used_percent
            FROM bot_guard_system_metric
            WHERE sampled_at >= '%s'
              AND sampled_at < '%s'
            GROUP BY day
            ORDER BY day ASC
        ", $from, $to));

        $indexed = [];

        foreach ($rows as $row) {
            $load1 = null !== $row['avg_load_1'] ? round((float) $row['avg_load_1'], 3) : null;
            $indexed[(string) $row['day']] = [
                'load1' => $load1,
                'cpuPercent' => $this->normalizeLoadToPercent($load1, $cpuCores),
                'memUsedPercent' => null !== $row['avg_mem_used_percent'] ? round((float) $row['avg_mem_used_percent'], 2) : null,
            ];
        }

        $out = [];
        $date = $dbToday->modify('-'.($days - 1).' days');

        while ($date <= $dbToday) {
            $key = $date->format('Y-m-d');
            $metric = $indexed[$key] ?? ['load1' => null, 'cpuPercent' => null, 'memUsedPercent' => null];
            $out[] = [
                'day' => $key,
                'load1' => $metric['load1'],
                'cpuPercent' => $metric['cpuPercent'],
                'memUsedPercent' => $metric['memUsedPercent'],
            ];
            $date = $date->modify('+1 day');
        }

        return $out;
    }

    /**
     * @return array<int,array{day:string,load1:?float,cpuPercent:?float,memUsedPercent:?float}>
     */
    private function safeSystemDaily(int $days): array
    {
        try {
            return $this->fetchSystemDaily($days);
        } catch (\Throwable $e) {
            $out = [];
            $end = new \DateTimeImmutable((new \DateTimeImmutable('today'))->format('Y-m-d'));
            $date = $end->modify('-'.($days - 1).' days');

            while ($date <= $end) {
                $out[] = [
                    'day' => $date->format('Y-m-d'),
                    'load1' => null,
                    'cpuPercent' => null,
                    'memUsedPercent' => null,
                ];
                $date = $date->modify('+1 day');
            }

            return $out;
        }
    }

    /**
     * @return array{hasData:bool,maxLoad1:?float,maxCpuPercent:?float,maxMemUsedPercent:?float,blocked:int,suspicious:int,cpuLevel:string,memLevel:string}
     */
    private function fetchPeaksLastHour(): array
    {
        $row = $this->connection->fetchAssociative("
            SELECT
                MAX(load_1) AS max_load_1,
                MAX(mem_used_percent) AS max_mem_used_percent
            FROM bot_guard_system_metric
            WHERE sampled_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $maxLoad = ($row && null !== $row['max_load_1']) ? (float) $row['max_load_1'] : null;
        $maxCpuPercent = $this->normalizeLoadToPercent($maxLoad, $this->getCpuCores());
        $maxMem = ($row && null !== $row['max_mem_used_percent']) ? (float) $row['max_mem_used_percent'] : null;
        $blocked = $this->safeInt("SELECT COUNT(*) FROM bot_guard_log WHERE blocked_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $suspicious = $this->safeInt("SELECT COUNT(*) FROM bot_guard_suspicious_event WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");

        return [
            'hasData' => null !== $maxLoad || null !== $maxMem,
            'maxLoad1' => null !== $maxLoad ? round($maxLoad, 3) : null,
            'maxCpuPercent' => $maxCpuPercent,
            'maxMemUsedPercent' => null !== $maxMem ? round($maxMem, 2) : null,
            'blocked' => $blocked,
            'suspicious' => $suspicious,
            'cpuLevel' => $this->resolveCpuLevel($maxCpuPercent),
            'memLevel' => $this->resolveMemLevel($maxMem),
        ];
    }

    /**
     * @return array{hasData:bool,maxLoad1:?float,maxCpuPercent:?float,maxMemUsedPercent:?float,blocked:int,suspicious:int,cpuLevel:string,memLevel:string}
     */
    private function safePeaksLastHour(): array
    {
        try {
            return $this->fetchPeaksLastHour();
        } catch (\Throwable $e) {
            return [
                'hasData' => false,
                'maxLoad1' => null,
                'maxCpuPercent' => null,
                'maxMemUsedPercent' => null,
                'blocked' => 0,
                'suspicious' => 0,
                'cpuLevel' => 'info',
                'memLevel' => 'info',
            ];
        }
    }

    private function resolveCpuLevel(?float $value): string
    {
        if (null === $value) {
            return 'info';
        }

        if ($value >= 85.0) {
            return 'danger';
        }

        if ($value >= 65.0) {
            return 'warn';
        }

        return 'info';
    }

    private function resolveMemLevel(?float $value): string
    {
        if (null === $value) {
            return 'info';
        }

        if ($value >= 85.0) {
            return 'danger';
        }

        if ($value >= 65.0) {
            return 'warn';
        }

        return 'info';
    }

    private function getCpuCores(): int
    {
        if (null !== $this->cpuCores) {
            return $this->cpuCores;
        }

        $cpuInfoPath = '/proc/cpuinfo';
        $cores = 1;

        if (is_readable($cpuInfoPath)) {
            $content = file_get_contents($cpuInfoPath);

            if (is_string($content) && '' !== trim($content)) {
                preg_match_all('/^processor\s*:\s*\d+/m', $content, $matches);
                $detected = isset($matches[0]) ? count($matches[0]) : 0;

                if ($detected > 0) {
                    $cores = $detected;
                }
            }
        }

        $this->cpuCores = max(1, $cores);

        return $this->cpuCores;
    }

    private function normalizeLoadToPercent(?float $load, int $cpuCores): ?float
    {
        if (null === $load) {
            return null;
        }

        $normalized = ($load / max(1, $cpuCores)) * 100;

        return round(max(0.0, min(100.0, $normalized)), 2);
    }
}

