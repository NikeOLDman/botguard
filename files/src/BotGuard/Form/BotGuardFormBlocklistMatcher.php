<?php

declare(strict_types=1);

namespace App\BotGuard\Form;

final class BotGuardFormBlocklistMatcher
{
    public function isBlockedName(string $name, string $blockedRaw): bool
    {
        $name = mb_strtolower(trim($name));
        if ('' === $name) {
            return false;
        }

        foreach ($this->parseLines($blockedRaw) as $pattern) {
            if ($this->matchesContains($name, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public function isBlockedEmail(string $email, string $blockedRaw): bool
    {
        $email = $this->normalizeEmail($email);
        if ('' === $email) {
            return false;
        }

        foreach ($this->parseLines($blockedRaw) as $pattern) {
            $pattern = $this->normalizeEmail($pattern);
            if ('' === $pattern) {
                continue;
            }
            if ($email === $pattern || (false !== strpos($email, $pattern) && strlen($pattern) >= 4)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function parseLines(string $raw): array
    {
        $items = preg_split('/[\r\n,]+/', $raw) ?: [];
        $out = [];

        foreach ($items as $item) {
            $item = trim($item);
            if ('' !== $item) {
                $out[] = $item;
            }
        }

        return $out;
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function matchesContains(string $value, string $pattern): bool
    {
        $pattern = mb_strtolower(trim($pattern));
        if ('' === $pattern) {
            return false;
        }

        return false !== mb_strpos($value, $pattern);
    }
}
