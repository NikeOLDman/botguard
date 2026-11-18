#!/usr/bin/env php
<?php

declare(strict_types=1);

const REQUIRED_PACKAGES = [
    'darvinstudio/darvin-admin-bundle' => '6.6.0',
    'darvinstudio/darvin-admin-frontend-bundle' => '6.2.0',
    'darvinstudio/darvin-ecommerce-bundle' => '10.0.0',
    'symfony/framework-bundle' => '4.4.0',
    'symfony/process' => '4.4.0',
];

$options = getopt('', ['project-dir:', 'dry-run', 'allow-untested-frontend']);
$dryRun = isset($options['dry-run']);
if (PHP_VERSION_ID < 70300) {
    fail('PHP 7.3 or newer is required.');
}
$moduleDir = dirname(__DIR__);
$projectDir = resolveProjectDir($options);

validateComposerLock($projectDir);
$installedPackages = getInstalledPackages($projectDir);
validateRequiredPackages($installedPackages, isset($options['allow-untested-frontend']));

[$copied, $updated] = copyModuleFiles($moduleDir.'/files', $projectDir, $dryRun);

echo sprintf("BotGuard %s. New files: %d, updated files: %d.\n", $dryRun ? 'dry run (no files written)' : 'deployment done', $copied, $updated);
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
function validateRequiredPackages(array $installedPackages, bool $allowUntestedFrontend): void
{
    foreach (REQUIRED_PACKAGES as $package => $minVersion) {
        if (!isset($installedPackages[$package])) {
            fail(sprintf('Required package "%s" is not installed.', $package));
        }

        $installedVersion = $installedPackages[$package];
        if ('darvinstudio/darvin-admin-frontend-bundle' === $package && 0 === strpos($installedVersion, 'dev-') && $allowUntestedFrontend) {
            fwrite(STDERR, "Warning: unversioned admin frontend explicitly accepted; verify its compatibility separately.\n");
            continue;
        }
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
    if (0 === strpos($version, 'dev-')) {
        return $version;
    }

    $dashPos = strpos($version, '-');
    if (false !== $dashPos) {
        $version = substr($version, 0, $dashPos);
    }

    return $version;
}

/**
 * @return array{int,int}
 */
function copyModuleFiles(string $sourceRoot, string $targetRoot, bool $dryRun): array
{
    if (!is_dir($sourceRoot)) {
        fail(sprintf('Module files directory does not exist: %s', $sourceRoot));
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $backupRoot = $targetRoot.'/var/bot_guard-install-backup/'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
    $backedUp = false;
    $copied = 0;
    $updated = 0;

    foreach ($iterator as $item) {
        $sourcePath = $item->getPathname();
        $relativePath = ltrim(substr($sourcePath, strlen($sourceRoot)), DIRECTORY_SEPARATOR);
        $targetPath = $targetRoot.DIRECTORY_SEPARATOR.$relativePath;

        if ($item->isDir()) {
            if (!$dryRun && !is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
                fail(sprintf('Unable to create directory: %s', $targetPath));
            }
            continue;
        }

        $targetDir = dirname($targetPath);
        if (!$dryRun && !is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            fail(sprintf('Unable to create directory: %s', $targetDir));
        }

        $sourceHash = hash_file('sha256', $sourcePath);
        $targetExists = is_file($targetPath);
        $targetHash = $targetExists ? hash_file('sha256', $targetPath) : null;

        if ($targetExists && $sourceHash === $targetHash) {
            continue;
        }

        if (!$dryRun && $targetExists) {
            $backupPath = $backupRoot.DIRECTORY_SEPARATOR.$relativePath;
            if (!is_dir(dirname($backupPath)) && !mkdir(dirname($backupPath), 0700, true) && !is_dir(dirname($backupPath))) {
                fail('Unable to create backup directory.');
            }
            if (!copy($targetPath, $backupPath)) {
                fail(sprintf('Unable to back up: %s', $relativePath));
            }
            $backedUp = true;
        }
        if (!$dryRun && !copy($sourcePath, $targetPath)) {
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

    if ($backedUp) {
        echo "Previous files saved in: ".$backupRoot."\n";
    }

    return [$copied, $updated];
}

function printPostInstallHints(string $projectDir): void
{
    echo "\nIntegration steps (not executed automatically):\n";
    echo "- Merge config/snippets/services.bot_guard.yaml into services in config/services.yaml (including _defaults.bind).\n";
    echo "- Configure bot_guard.integration_prefixes and bot_guard.integration_post_paths for authenticated APIs only.\n";
    echo "- Review catalog budgets, slots and sort fields. See INSTALL.md.\n";
    echo "- config/packages/bot_guard.yaml already registers admin sections and translations.\n";
    echo "- Run migrations, clear production cache, and warm crawler identities per INSTALL.md.\n";
    echo "- Preserve var/bot_guard across releases; it is independent of var/cache.\n";

}

/**
 * @return never
 */
function fail(string $message): void
{
    fwrite(STDERR, sprintf("Error: %s\n", $message));
    exit(1);
}
