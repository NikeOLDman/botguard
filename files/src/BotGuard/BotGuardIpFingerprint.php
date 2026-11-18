<?php

declare(strict_types=1);

namespace App\BotGuard;

final class BotGuardIpFingerprint
{
    public static function build(string $ip): string
    {
        $ip = self::normalize($ip);
        if ('' === $ip) {
            return 'no-ip';
        }

        if (false !== strpos($ip, ':')) {
            return bin2hex(substr((string) inet_pton($ip), 0, 8));
        }

        $parts = explode('.', $ip);
        if (4 !== count($parts)) {
            return $ip;
        }

        return $parts[0].'.'.$parts[1].'.'.$parts[2].'.0';
    }

    public static function normalize(string $ip): string
    {
        $binary = @inet_pton(trim($ip));
        if (false === $binary) {
            return '';
        }
        if (16 === strlen($binary) && substr($binary, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            $binary = substr($binary, 12);
        }

        return (string) inet_ntop($binary);
    }
}
