<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Composer;

use Vtinnovations\GuardianTypo3\Domain\Filesystem\PathNormalizer;

/**
 * Authoritative view of Composer PATH-repository packages (local packages that
 * Composer symlinks into vendor/, e.g. vendor/vtinnovations/guardian-typo3 ->
 * ../../packages/guardian-typo3).
 *
 * The source of truth is `vendor/composer/installed.json`, whose per-package
 * `install-path` is recorded relative to `vendor/composer/`. That relative value
 * is DEPTH-DEPENDENT, so it is always resolved against a caller-supplied FINAL
 * vendor location — never against the (possibly deeper) staging directory the
 * data was read from. This is what lets Recovery reconstruct correct symlink
 * targets after extracting/rebuilding vendor at a different depth.
 *
 * A package is treated as a path repository when its install location resolves
 * OUTSIDE the vendor directory (a normal package resolves inside it).
 */
final class PathRepositoryInspector
{
    public function __construct(
        private readonly PathNormalizer $pathNormalizer,
    ) {
    }

    /**
     * @param string $installedJsonVendor absolute vendor dir to READ installed.json from
     * @param string $finalVendor         absolute vendor dir the paths must be valid FOR
     * @param string $projectPath         absolute project root (containment boundary)
     * @return list<array{package: string, vendor_link: string, source_path: string, install_path: string, inside_project: bool, original_target: ?string}>
     */
    public function inspect(string $installedJsonVendor, string $finalVendor, string $projectPath): array
    {
        $finalVendor = rtrim($this->pathNormalizer->normalize($finalVendor), '/');
        $projectPath = rtrim($this->pathNormalizer->normalize($projectPath), '/');
        $file = rtrim($installedJsonVendor, '/') . '/composer/installed.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!\is_array($data)) {
            return [];
        }
        $packages = $data['packages'] ?? $data;
        if (!\is_array($packages)) {
            return [];
        }

        $out = [];
        foreach ($packages as $pkg) {
            if (!\is_array($pkg)) {
                continue;
            }
            $name = (string) ($pkg['name'] ?? '');
            $installPath = $pkg['install-path'] ?? null;
            if ($name === '' || !\is_string($installPath) || $installPath === '') {
                continue;
            }
            // Resolve the install path AS IF vendor were at its final location.
            $sourceAbs = $this->pathNormalizer->normalize($finalVendor . '/composer/' . $installPath);

            // Normal (non-path) packages resolve inside vendor/ — skip them.
            if ($this->pathNormalizer->isContained($finalVendor, $sourceAbs)) {
                continue;
            }
            $insideProject = $this->pathNormalizer->isContained($projectPath, $sourceAbs);
            $vendorLink = 'vendor/' . $name;
            $original = @readlink(rtrim($projectPath, '/') . '/' . $vendorLink);

            $out[] = [
                'package' => $name,
                'vendor_link' => $vendorLink,
                'source_path' => $insideProject ? ltrim(substr($sourceAbs, \strlen($projectPath)), '/') : $sourceAbs,
                'install_path' => $installPath,
                'inside_project' => $insideProject,
                'original_target' => $original !== false ? $original : null,
            ];
        }

        return $out;
    }
}
