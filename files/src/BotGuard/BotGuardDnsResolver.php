<?php

declare(strict_types=1);

namespace App\BotGuard;

use Symfony\Component\Process\Process;

/** Bound DNS latency even when the hosting resolver is unavailable. */
class BotGuardDnsResolver
{
    public function resolve(string $ip): array
    {
        $script = <<<'PHP'
$ip = $argv[1];
$host = @gethostbyaddr($ip);
$ips = [];
if (is_string($host) && $host !== $ip) {
    foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $row) {
        if (isset($row['ip'])) $ips[] = $row['ip'];
        if (isset($row['ipv6'])) $ips[] = $row['ipv6'];
    }
}
echo json_encode(['host' => $host, 'ips' => $ips]);
PHP;
        $process = new Process([PHP_BINDIR.'/php', '-d', 'display_errors=0', '-r', $script, $ip]);
        $process->setTimeout(0.8);
        try {
            $process->run();
            $result = json_decode($process->getOutput(), true);

            return $process->isSuccessful() && is_array($result) ? $result : [];
        } catch (\Throwable $error) {
            return [];
        }
    }
}
