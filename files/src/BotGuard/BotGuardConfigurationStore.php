<?php

declare(strict_types=1);

namespace App\BotGuard;

/** Last known settings survive cache eviction and temporary database failures. */
final class BotGuardConfigurationStore
{
    private $directory;
    private $values = [];

    public function __construct(string $directory)
    {
        $this->directory = $directory;
    }

    public function save(string $name, array $value): void
    {
        $this->values[$name] = $value;
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return;
        }
        $temporary = @tempnam($this->directory, 'settings-');
        if (false === $temporary) {
            return;
        }
        if (false === @file_put_contents($temporary, json_encode($value)) || !@rename($temporary, $this->path($name))) {
            @unlink($temporary);
        }
    }

    public function load(string $name): ?array
    {
        if (isset($this->values[$name])) {
            return $this->values[$name];
        }
        $raw = @file_get_contents($this->path($name));
        $value = false === $raw ? null : json_decode($raw, true);

        return is_array($value) ? $value : null;
    }

    private function path(string $name): string
    {
        return $this->directory.'/'.hash('sha256', $name).'.json';
    }
}
