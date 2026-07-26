<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Update;

use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Domain\Update\PackageStatus;
use Vtinnovations\GuardianTypo3\Infrastructure\Packages\InstalledPackages;
use Vtinnovations\GuardianTypo3\Infrastructure\Process\SymfonyProcessCommandExecutor;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\ComposerEnvironment;

/**
 * Real online update availability check.
 *
 * Runs `composer outdated --direct --format=json` under the configured PHP CLI
 * and merges the result onto the installed-package set, classifying every
 * package with a language-neutral {@see PackageStatus} code. This replaces the
 * "online update checks are not active yet" placeholder. It is READ-ONLY — it
 * never runs `composer update` and never mutates the project.
 *
 * Failures are classified (network / authentication / repository / resolution /
 * unavailable) and returned as machine codes so the UI can react precisely. No
 * Composer credentials or repository tokens are ever returned.
 */
final class PackageUpdateChecker
{
    public function __construct(
        private readonly InstalledPackages $installedPackages,
        private readonly ComposerEnvironment $composerEnvironment,
        private readonly SymfonyProcessCommandExecutor $executor,
        private readonly ProjectEnvironmentInterface $environment,
    ) {
    }

    /**
     * @return array{packages: list<array<string, mixed>>, checked_at: string, source: string, error: ?string, error_code: ?string}
     */
    public function check(): array
    {
        $installed = $this->installedPackages->listInstalled();

        $php = $this->composerEnvironment->phpBinary();
        $composer = $this->composerEnvironment->composerBinary();
        if ($php === null || $composer === null) {
            return $this->result($this->classifyInstalledOnly($installed), 'installed_only', 'Composer or the PHP CLI binary could not be located.', 'composer_unavailable');
        }
        if (!$this->executor->isAvailable()) {
            return $this->result($this->classifyInstalledOnly($installed), 'installed_only', 'Process execution is not available in this environment.', 'exec_unavailable');
        }

        $factory = new ComposerCommandFactory($php, $composer, rtrim($this->environment->projectPath(), '/'));
        $result = $this->executor->run($factory->outdated(true, $this->composerEnvironment->ignorePlatformFlags()));

        if (!$result->isSuccessful()) {
            // Composer outdated can fail on older Composer/metadata setups;
            // retry with the Composer 2-compatible package metadata command.
            $fallback = $this->executor->run($factory->showCoreReleases($this->composerEnvironment->ignorePlatformFlags()));
            if ($fallback->isSuccessful()) {
                $latestByName = $this->parseOutdated($fallback->stdout);
                return $this->result($this->mergeCoreMetadata($installed, $latestByName), 'composer_show', null, null);
            }
            [$code, $message] = $this->classifyError($result->combinedOutput());

            return $this->result($this->classifyInstalledOnly($installed), 'composer', $message, $code);
        }

        $latestByName = $this->parseOutdated($result->stdout);
        $packages = [];
        foreach ($installed as $pkg) {
            $name = $pkg['name'];
            $latest = $latestByName[$name] ?? '';
            $status = PackageStatus::classify($pkg['current'], $latest, $pkg['abandoned']);
            $packages[] = [
                'name' => $name,
                'current' => $pkg['current'],
                'latest' => $latest,
                'type' => $pkg['type'],
                'abandoned' => $pkg['abandoned'],
                'status' => $status->value,
                'has_update' => $status->hasUpdate(),
            ];
        }

        return $this->result($packages, 'composer', null, null);
    }

    private function mergeCoreMetadata(array $installed, array $latestByName): array
    {
        return array_map(static function (array $pkg) use ($latestByName): array {
            $latest = $latestByName[$pkg['name']] ?? '';
            $status = PackageStatus::classify($pkg['current'], $latest, $pkg['abandoned']);
            return $pkg + ['latest' => $latest, 'status' => $status->value, 'has_update' => $status->hasUpdate()];
        }, $installed);
    }

    /**
     * @param list<array<string,mixed>> $installed
     * @return list<array<string,mixed>>
     */
    private function classifyInstalledOnly(array $installed): array
    {
        $packages = [];
        foreach ($installed as $pkg) {
            $status = $pkg['abandoned'] ? PackageStatus::Abandoned : PackageStatus::Unknown;
            $packages[] = [
                'name' => $pkg['name'],
                'current' => $pkg['current'],
                'latest' => '',
                'type' => $pkg['type'],
                'abandoned' => $pkg['abandoned'],
                'status' => $status->value,
                'has_update' => false,
            ];
        }

        return $packages;
    }

    /**
     * @return array<string, string> name => latest version
     */
    private function parseOutdated(string $json): array
    {
        $data = json_decode($json, true);
        if (!\is_array($data) || !isset($data['installed']) || !\is_array($data['installed'])) {
            return [];
        }
        $map = [];
        foreach ($data['installed'] as $entry) {
            if (\is_array($entry) && isset($entry['name'])) {
                $map[(string) $entry['name']] = ltrim((string) ($entry['latest'] ?? ''), 'v');
            }
        }

        return $map;
    }

    /**
     * @return array{0:string,1:string} [code, message]
     */
    private function classifyError(string $output): array
    {
        $lower = strtolower($output);
        if (str_contains($lower, 'could not authenticate') || str_contains($lower, ' 401 ') || str_contains($lower, ' 403 ') || str_contains($lower, 'authentication required')) {
            return ['auth_error', 'Composer could not authenticate against a repository.'];
        }
        if (str_contains($lower, 'could not resolve host') || str_contains($lower, 'timed out') || str_contains($lower, 'network is unreachable') || str_contains($lower, 'curl error')) {
            return ['network_error', 'Composer could not reach the package repository (network error).'];
        }
        if (str_contains($lower, 'could not be resolved') || str_contains($lower, 'your requirements')) {
            return ['resolution_error', 'Composer could not resolve the dependency graph.'];
        }
        if (str_contains($lower, 'repository') && str_contains($lower, 'could not be loaded')) {
            return ['repository_error', 'A configured Composer repository could not be loaded.'];
        }

        return ['composer_error', 'The online update check failed.'];
    }

    /**
     * @param list<array<string,mixed>> $packages
     * @return array{packages: list<array<string, mixed>>, checked_at: string, source: string, error: ?string, error_code: ?string}
     */
    private function result(array $packages, string $source, ?string $error, ?string $errorCode): array
    {
        return [
            'packages' => $packages,
            'checked_at' => gmdate('c'),
            'source' => $source,
            'error' => $error,
            'error_code' => $errorCode,
        ];
    }
}
