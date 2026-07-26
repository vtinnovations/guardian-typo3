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

use Vtinnovations\GuardianTypo3\Application\Backup\ComponentSelection;
use Vtinnovations\GuardianTypo3\Domain\Backup\BackupComponent;

/**
 * Server-validated selection of components to RESTORE.
 *
 * Unlike a backup selection, recovery has no forced baseline — every component
 * is opt-in — and the selection is intersected with what the chosen backup
 * actually contains. A component is restored only when the request contains a
 * strict boolean true AND the backup manifest lists it as present.
 */
final class RecoveryComponentSelection
{
    /**
     * @param list<BackupComponent> $selected
     * @param list<BackupComponent> $missing components requested but not in the backup
     */
    private function __construct(
        private readonly array $selected,
        private readonly array $missing,
    ) {
    }

    /**
     * @param array<string, mixed> $components
     */
    public static function fromRequest(array $components, BackupManifest $manifest): self
    {
        $selected = [];
        $missing = [];
        foreach (BackupComponent::cases() as $component) {
            if (($components[$component->value] ?? null) !== true) {
                continue;
            }
            if ($manifest->hasComponent($component)) {
                $selected[] = $component;
            } else {
                $missing[] = $component;
            }
        }

        return new self($selected, $missing);
    }

    public function isEmpty(): bool
    {
        return $this->selected === [];
    }

    /**
     * @return list<BackupComponent>
     */
    public function selected(): array
    {
        return $this->selected;
    }

    /**
     * @return list<BackupComponent>
     */
    public function missing(): array
    {
        return $this->missing;
    }

    public function contains(BackupComponent $component): bool
    {
        return \in_array($component, $this->selected, true);
    }

    /**
     * Maps this restore selection to a backup {@see ComponentSelection}, used to
     * snapshot the CURRENT state of exactly the components about to be overwritten
     * before recovery begins.
     */
    public function toBackupSelection(): ComponentSelection
    {
        $request = [];
        foreach ($this->selected as $component) {
            $request[$component->value] = true;
        }

        return ComponentSelection::fromRequest($request);
    }
}
