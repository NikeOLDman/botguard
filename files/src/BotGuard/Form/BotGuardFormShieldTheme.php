<?php

declare(strict_types=1);

namespace App\BotGuard\Form;

final class BotGuardFormShieldTheme
{
    public const THEME_BLUE = 'blue';
    public const THEME_RED = 'red';
    public const THEME_CYAN = 'cyan';
    public const THEME_GREEN = 'green';
    public const THEME_ORANGE = 'orange';

    public const DEFAULT_THEME = self::THEME_BLUE;

    public const LOGO_TVERDYNYA = '/assets/images/bot-guard/logo.png';

    /**
     * @return string[]
     */
    public static function allowedThemes(): array
    {
        return [
            self::THEME_BLUE,
            self::THEME_RED,
            self::THEME_CYAN,
            self::THEME_GREEN,
            self::THEME_ORANGE,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function themeChoices(): array
    {
        return [
            'Синий' => self::THEME_BLUE,
            'Красный' => self::THEME_RED,
            'Голубой' => self::THEME_CYAN,
            'Зелёный' => self::THEME_GREEN,
            'Оранжевый' => self::THEME_ORANGE,
        ];
    }

    public static function normalizeTheme(?string $theme): string
    {
        $theme = is_string($theme) ? trim($theme) : '';

        return in_array($theme, self::allowedThemes(), true) ? $theme : self::DEFAULT_THEME;
    }

    public static function resolveLogoUrl(?string $logoUrl, string $defaultLogoUrl): string
    {
        $logoUrl = is_string($logoUrl) ? trim($logoUrl) : '';

        if ('' === $logoUrl) {
            return $defaultLogoUrl;
        }

        if (0 === strpos($logoUrl, '/')) {
            return $logoUrl;
        }

        if (0 === strpos($logoUrl, 'http://') || 0 === strpos($logoUrl, 'https://')) {
            return $logoUrl;
        }

        return '/'.ltrim($logoUrl, '/');
    }
}
