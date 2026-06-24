<?php

declare(strict_types=1);

namespace App\BotGuard;

final class BotGuardIpFingerprint
{
    public static function build(string $ip): string
    {
        $ip = trim($ip);
        if ('' === $ip) {
            return 'no-ip';
        }

        if (false !== strpos($ip, ':')) {
            $parts = explode(':', $ip);

            return implode(':', array_slice($parts, 0, 4));
        }

        $parts = explode('.', $ip);
        if (4 !== count($parts)) {
            return $ip;
        }

        return $parts[0].'.'.$parts[1].'.'.$parts[2].'.0';
    }
}
