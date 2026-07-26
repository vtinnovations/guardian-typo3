<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Packages;

use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;

/**
 * Read-only inspection of installed Composer packages.
 *
 * Ports the CMS-independent half of the audited Contao PackageInspector: it
 * reads vendor/composer/installed.json and returns the installed set. It does
 * NOT query Packagist (no outbound HTTP in this phase), so "latest"/"has_update"
 * are always empty/false here — the network refresh is a later phase. TYPO3
 * packages are surfaced first, matching the original's Contao-first ordering.
 */
final class InstalledPackages
{
    public function __construct(
        private readonly ProjectEnvironmentInterface $environment,
    ) {
    }

    /**
     * @return list<array{name: string, current: string, type: string, description: string, abandoned: bool, has_update: bool, latest: string}>
     */
    public function listInstalled(): array
    {
        $file = rtrim($this->environment->projectPath(), '/') . '/vendor/composer/installed.json';
        if (!is_file($file)) {
            return [];
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        if (!\is_array($data)) {
            return [];
        }

        $packages = $data['packages'] ?? $data;
        if (!\is_array($packages)) {
            return [];
        }

        $result = [];
        foreach ($packages as $pkg) {
            if (!\is_array($pkg) || empty($pkg['name'])) {
                continue;
            }
            $result[] = [
                'name' => (string) $pkg['name'],
                'current' => ltrim((string) ($pkg['version'] ?? ''), 'v'),
                'type' => (string) ($pkg['type'] ?? 'library'),
                'description' => (string) ($pkg['description'] ?? ''),
                'abandoned' => isset($pkg['abandoned']),
                'has_update' => false,
                'latest' => '',
            ];
        }

        usort($result, static function (array $a, array $b): int {
            $aTypo3 = str_contains($a['name'], 'typo3') ? 0 : 1;
            $bTypo3 = str_contains($b['name'], 'typo3') ? 0 : 1;
            if ($aTypo3 !== $bTypo3) {
                return $aTypo3 - $bTypo3;
            }

            return strcmp($a['name'], $b['name']);
        });

        return $result;
    }

    public function count(): int
    {
        return \count($this->listInstalled());
    }

    /**
     * Returns the installed version of a single package, or '' if not present.
     */
    public function versionOf(string $packageName): string
    {
        foreach ($this->listInstalled() as $pkg) {
            if ($pkg['name'] === $packageName) {
                return $pkg['current'];
            }
        }

        return '';
    }
}
