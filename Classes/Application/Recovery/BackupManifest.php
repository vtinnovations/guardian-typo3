<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Recovery;

use Vtinnovations\GuardianTypo3\Domain\Backup\BackupComponent;

/**
 * Immutable, validated view over a Guardian backup manifest. Only the fields
 * recovery needs are exposed as typed accessors; unknown/legacy keys are
 * ignored. The raw array remains available for the preflight/UI.
 */
final class BackupManifest
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(private readonly array $raw)
    {
    }

    public function id(): string
    {
        return (string) ($this->raw['id'] ?? '');
    }

    public function status(): string
    {
        return (string) ($this->raw['status'] ?? '');
    }

    public function type(): string
    {
        return (string) ($this->raw['type'] ?? '');
    }

    public function isCompleted(): bool
    {
        return $this->status() === 'completed';
    }

    public function checksum(): string
    {
        return (string) ($this->raw['checksum'] ?? '');
    }

    public function checksumAlgo(): string
    {
        return (string) ($this->raw['checksum_algo'] ?? 'sha256');
    }

    public function archiveSize(): int
    {
        return (int) ($this->raw['archive_size'] ?? 0);
    }

    public function databaseSize(): int
    {
        return (int) ($this->raw['database_size'] ?? 0);
    }

    public function typo3Version(): string
    {
        return (string) ($this->raw['typo3_version'] ?? '');
    }

    public function phpVersion(): string
    {
        return (string) ($this->raw['php_version'] ?? '');
    }

    public function hostname(): string
    {
        return (string) ($this->raw['hostname'] ?? '');
    }

    public function createdAt(): string
    {
        return (string) ($this->raw['created_at'] ?? '');
    }

    /**
     * @return array<string, bool>
     */
    public function componentFlags(): array
    {
        $flags = $this->raw['components'] ?? [];

        return \is_array($flags) ? $flags : [];
    }

    /**
     * The components actually present in this backup (selected at backup time
     * and known to the current Guardian version).
     *
     * @return list<BackupComponent>
     */
    public function availableComponents(): array
    {
        $flags = $this->componentFlags();
        $available = [];
        foreach (BackupComponent::cases() as $component) {
            if (($flags[$component->value] ?? false) === true) {
                $available[] = $component;
            }
        }

        return $available;
    }

    public function hasComponent(BackupComponent $component): bool
    {
        return ($this->componentFlags()[$component->value] ?? false) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
