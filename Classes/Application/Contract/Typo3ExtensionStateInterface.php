<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Contract;

use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * A thin port over TYPO3's own package activation state, keyed by extension key.
 *
 * Enable/Disable go through TYPO3's supported {@see \TYPO3\CMS\Core\Package\PackageManager}
 * API (which persists package state itself); Guardian NEVER edits the generated
 * package-state files by hand. The port exists so the policy and the controller
 * can be unit-tested with a fake, and so a platform that does not support the
 * operation surfaces a precise reason instead of a fake action.
 */
interface Typo3ExtensionStateInterface
{
    /**
     * Whether the underlying TYPO3 package API is usable in this context.
     */
    public function isAvailable(): bool;

    /**
     * Whether the extension key is currently active/loaded.
     */
    public function isActive(string $extensionKey): bool;

    /**
     * Whether TYPO3 marks the package as protected (core / system / required).
     */
    public function isProtected(string $extensionKey): bool;

    /**
     * Deactivate the extension via the supported API.
     *
     * @throws GuardianException with a machine reason on failure
     */
    public function deactivate(string $extensionKey): void;

    /**
     * Activate the extension via the supported API.
     *
     * @throws GuardianException with a machine reason on failure
     */
    public function activate(string $extensionKey): void;
}
