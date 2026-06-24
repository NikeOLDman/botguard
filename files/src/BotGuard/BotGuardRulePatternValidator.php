<?php

declare(strict_types=1);

namespace App\BotGuard;

use App\Entity\BotGuard\BotGuardRule;

final class BotGuardRulePatternValidator
{
    public static function assertValid(string $type, string $pattern): void
    {
        $pattern = trim($pattern);
        if ('' === $pattern) {
            throw new \InvalidArgumentException('Шаблон правила не может быть пустым.');
        }

        if (BotGuardRule::TYPE_USER_AGENT_REGEX !== $type) {
            return;
        }

        set_error_handler(static function (): bool {
            return true;
        });

        try {
            if (false === preg_match($pattern, '')) {
                $error = preg_last_error();
                if (PREG_NO_ERROR !== $error) {
                    throw new \InvalidArgumentException('Некорректное регулярное выражение. Используйте формат PHP: /pattern/ или #pattern#');
                }
            }
        } finally {
            restore_error_handler();
        }
    }
}
