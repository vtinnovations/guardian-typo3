<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Backup;

use Vtinnovations\GuardianTypo3\Domain\Backup\BackupComponent;

/**
 * Immutable, server-validated selection of backup components.
 *
 * Built from an untrusted request payload but never trusts it blindly: the
 * baseline components (composer files + database) are forced on by policy, and
 * every optional component (including {@see BackupComponent::Fileadmin}) is
 * included only when the payload contains a strict boolean `true`. Whether the
 * corresponding path actually exists / is safe is decided later, server-side,
 * by the file collector.
 */
final class ComponentSelection
{
    /**
     * @param array<string, bool> $selected component wire-key => selected
     */
    private function __construct(private readonly array $selected)
    {
    }

    /**
     * @param array<string, mixed> $components
     */
    public static function fromRequest(array $components): self
    {
        $selected = [];
        foreach (BackupComponent::baseline() as $component) {
            $selected[$component->value] = true;
        }
        foreach (BackupComponent::optional() as $component) {
            // Strict: only an explicit boolean true selects the component.
            $selected[$component->value] = ($components[$component->value] ?? null) === true;
        }

        return new self($selected);
    }

    /**
     * Full selection for a scheduled "Full" backup profile with the given
     * directory components enabled.
     *
     * @param array<string, bool> $directoryComponents e.g. ['vendor' => true, 'configuration' => true, ...]
     */
    public static function forFull(array $directoryComponents): self
    {
        $request = [];
        foreach (BackupComponent::optional() as $component) {
            $request[$component->value] = ($directoryComponents[$component->value] ?? false) === true;
        }

        return self::fromRequest($request);
    }

    /**
     * A "Mini" backup: database + composer files only (all directories off).
     */
    public static function mini(): self
    {
        return self::fromRequest([]);
    }

    public function isSelected(BackupComponent $component): bool
    {
        return $this->selected[$component->value] ?? false;
    }

    /**
     * @return list<BackupComponent>
     */
    public function selectedComponents(): array
    {
        $result = [];
        foreach (BackupComponent::cases() as $component) {
            if ($this->isSelected($component)) {
                $result[] = $component;
            }
        }

        return $result;
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return $this->selected;
    }
}
