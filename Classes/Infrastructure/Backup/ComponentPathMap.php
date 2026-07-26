<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Backup;

use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Domain\Backup\BackupComponent;
use Vtinnovations\GuardianTypo3\Domain\Filesystem\PathNormalizer;

/**
 * Maps a {@see BackupComponent} to its project-relative archive prefix and its
 * absolute target on disk. This is the single source of truth for where each
 * component lives; it mirrors the prefixes produced by {@see FileCollector} at
 * backup time so recovery reconstructs exactly the same layout.
 */
final class ComponentPathMap
{
    private string $projectPath;
    private string $publicRelative;

    public function __construct(
        private readonly ProjectEnvironmentInterface $environment,
        private readonly PathNormalizer $pathNormalizer,
    ) {
        $this->projectPath = rtrim($this->pathNormalizer->normalize($this->environment->projectPath()), '/');
        $public = rtrim($this->pathNormalizer->normalize($this->environment->publicPath()), '/');
        $this->publicRelative = str_starts_with($public, $this->projectPath . '/')
            ? substr($public, \strlen($this->projectPath) + 1)
            : 'public';
    }

    public function projectPath(): string
    {
        return $this->projectPath;
    }

    /**
     * Project-relative archive prefix (forward slashes), or null for the
     * database pseudo-component.
     */
    public function prefix(BackupComponent $component): ?string
    {
        return match ($component) {
            BackupComponent::ComposerJson => 'composer.json',
            BackupComponent::ComposerLock => 'composer.lock',
            BackupComponent::Vendor => 'vendor',
            BackupComponent::Configuration => 'config',
            BackupComponent::Packages => 'packages',
            BackupComponent::Templates => 'templates',
            BackupComponent::Fileadmin => $this->publicRelative . '/fileadmin',
            BackupComponent::PublicAssets => $this->publicRelative . '/_assets',
            BackupComponent::Database => null,
        };
    }

    /**
     * Absolute target path for a component, or null for the database.
     */
    public function target(BackupComponent $component): ?string
    {
        $prefix = $this->prefix($component);

        return $prefix === null ? null : $this->projectPath . '/' . $prefix;
    }

    public function isDirectory(BackupComponent $component): bool
    {
        return !$component->isSingleFile() && !$component->isDatabase();
    }
}
