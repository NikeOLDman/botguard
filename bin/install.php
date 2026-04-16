#!/usr/bin/env php
<?php

declare(strict_types=1);

const REQUIRED_PACKAGES = [
    'darvinstudio/darvin-admin-bundle' => '6.6.0',
    'darvinstudio/darvin-admin-frontend-bundle' => '6.2.0',
];

$options = getopt('', ['project-dir::']);
$moduleDir = dirname(__DIR__);
$projectDir = resolveProjectDir($options);

validateComposerLock($projectDir);
$installedPackages = getInstalledPackages($projectDir);
validateRequiredPackages($installedPackages);

[$copied, $updated] = copyModuleFiles($moduleDir.'/files', $projectDir);

echo sprintf("BotGuard deployment done. New files: %d, updated files: %d.\n", $copied, $updated);
printPostInstallHints($projectDir);

function resolveProjectDir(array $options): string
{
    $rawProjectDir = isset($options['project-dir']) ? (string) $options['project-dir'] : getcwd();
    $projectDir = realpath($rawProjectDir);

    if (false === $projectDir) {
        fail(sprintf('Project directory does not exist: %s', $rawProjectDir));
    }

    return $projectDir;
}

function validateComposerLock(string $projectDir): void
{
    $lockFile = $projectDir.'/composer.lock';

    if (!is_file($lockFile)) {
        fail(sprintf('composer.lock was not found in project directory: %s', $projectDir));
    }
}

/**
 * @return array<string,string>
 */
function getInstalledPackages(string $projectDir): array
{
    $lockFile = $projectDir.'/composer.lock';
    $content = file_get_contents($lockFile);

    if (false === $content) {
        fail(sprintf('Unable to read composer.lock: %s', $lockFile));
    }

    $decoded = json_decode($content, true);

    if (!is_array($decoded)) {
        fail('Invalid composer.lock JSON.');
    }

    $packages = [];
    $all = array_merge($decoded['packages'] ?? [], $decoded['packages-dev'] ?? []);

    foreach ($all as $package) {
        if (!isset($package['name'], $package['version'])) {
            continue;
        }

        $packages[(string) $package['name']] = normalizeVersion((string) $package['version']);
    }

    return $packages;
}

/**
 * @param array<string,string> $installedPackages
 */
function validateRequiredPackages(array $installedPackages): void
{
    foreach (REQUIRED_PACKAGES as $package => $minVersion) {
        if (!isset($installedPackages[$package])) {
            fail(sprintf('Required package "%s" is not installed.', $package));
        }

        $installedVersion = $installedPackages[$package];
        if (version_compare($installedVersion, $minVersion, '<')) {
            fail(sprintf(
                'Package "%s" version is too old: %s. Required: >= %s.',
                $package,
                $installedVersion,
                $minVersion
            ));
        }
    }
}

function normalizeVersion(string $version): string
{
    $version = trim($version);
    $version = ltrim($version, 'v');

    $dashPos = strpos($version, '-');
    if (false !== $dashPos) {
        $version = substr($version, 0, $dashPos);
    }

    return $version;
}

/**
 * @return array{int,int}
 */
function copyModuleFiles(string $sourceRoot, string $targetRoot): array
{
    if (!is_dir($sourceRoot)) {
        fail(sprintf('Module files directory does not exist: %s', $sourceRoot));
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $copied = 0;
    $updated = 0;

    foreach ($iterator as $item) {
        $sourcePath = $item->getPathname();
        $relativePath = ltrim(substr($sourcePath, strlen($sourceRoot)), DIRECTORY_SEPARATOR);
        $targetPath = $targetRoot.DIRECTORY_SEPARATOR.$relativePath;

        if ($item->isDir()) {
            if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
                fail(sprintf('Unable to create directory: %s', $targetPath));
            }
            continue;
        }

        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            fail(sprintf('Unable to create directory: %s', $targetDir));
        }

        $sourceHash = hash_file('sha256', $sourcePath);
        $targetExists = is_file($targetPath);
        $targetHash = $targetExists ? hash_file('sha256', $targetPath) : null;

        if ($targetExists && $sourceHash === $targetHash) {
            continue;
        }

        if (!copy($sourcePath, $targetPath)) {
            fail(sprintf('Unable to copy file: %s', $relativePath));
        }

        if ($targetExists) {
            ++$updated;
            echo sprintf("[updated] %s\n", $relativePath);
        } else {
            ++$copied;
            echo sprintf("[new] %s\n", $relativePath);
        }
    }

    return [$copied, $updated];
}

function printPostInstallHints(string $projectDir): void
{
    echo "\nCheck these integration points:\n";
    echo "- Merge section snippet from config/snippets/darvin_admin.sections.yaml into config/packages/darvin_admin.yaml\n";
    echo "- Merge translation entries from translations/admin.ru.bot_guard.yaml into translations/admin.ru.yaml\n";
    echo "- Run migration: php bin/console doctrine:migrations:migrate --no-interaction\n";
    echo "- Run cache clear: php bin/console cache:clear --env=prod\n";
    echo "- Add cleanup cron: php bin/console app:bot-guard:cleanup --env=prod\n";

    $darvinAdminConfig = $projectDir.'/config/packages/darvin_admin.yaml';
    if (is_file($darvinAdminConfig)) {
        $content = file_get_contents($darvinAdminConfig);
        if (false !== $content && false === strpos($content, 'App\Entity\BotGuard\BotGuardSettings')) {
            echo "Warning: BotGuard sections are not found in config/packages/darvin_admin.yaml\n";
        }
    }
}

/**
 * @return never
 */
function fail(string $message): void
{
    fwrite(STDERR, sprintf("Error: %s\n", $message));
    exit(1);
}
