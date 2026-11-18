<?php

declare(strict_types=1);

// Keep the vendor source and both published builds consistent after composer install.
// No build tooling is needed on shared hosting. A changed upstream module requires review.
$directory = $argv[1] ?? dirname(__DIR__).'/vendor/darvinstudio/darvin-admin-frontend-bundle';
$sourceOld = "if (!(url && Notification.permission === 'granted')) reject();";
$sourceNew = "if (!(url && Notification.permission === 'granted')) return reject();";
$files = [
    'Resources/frontend/blocks/push-widget/push-widget.js' => [$sourceOld, $sourceNew],
    'Resources/public/assets/build-dev/app.js' => [$sourceOld, $sourceNew],
    'Resources/public/assets/build/app.js' => [
        'n&&"granted"===Notification.permission||t(),fetch(n)',
        'if(!n||"granted"!==Notification.permission)return t();fetch(n)',
    ],
];

$temporaryFiles = [];
try {
    $updates = [];
    foreach ($files as $relative => [$old, $new]) {
        $path = $directory.'/'.$relative;
        $content = file_get_contents($path);
        if (false === $content) {
            throw new RuntimeException('Cannot read '.$relative);
        }
        $oldCount = substr_count($content, $old);
        $newCount = substr_count($content, $new);
        if (0 === $oldCount && 1 === $newCount) {
            continue;
        }
        if (1 !== $oldCount || 0 !== $newCount) {
            throw new RuntimeException('Unexpected notification module in '.$relative.'; review the upstream change before deploying.');
        }
        $updates[$path] = str_replace($old, $new, $content);
    }
    // Validate every file first; publish each update atomically (vendor is shared).
    foreach ($updates as $path => $content) {
        $temporary = tempnam(dirname($path), '.notification-fix-');
        if (false === $temporary) {
            throw new RuntimeException('Cannot stage '.$path);
        }
        $temporaryFiles[$path] = $temporary;
        if (strlen($content) !== file_put_contents($temporary, $content, LOCK_EX)
            || !chmod($temporary, fileperms($path) & 0777)
        ) {
            throw new RuntimeException('Cannot write '.$path);
        }
    }
    foreach ($temporaryFiles as $path => $temporary) {
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Cannot publish '.$path);
        }
        unset($temporaryFiles[$path]);
    }
    echo 'Admin notification polling fix: '.count($updates)." file(s) updated.\n";
} catch (Throwable $exception) {
    foreach ($temporaryFiles as $temporary) {
        @unlink($temporary);
    }
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
