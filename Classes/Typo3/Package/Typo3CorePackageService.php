<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Typo3\Package;

use TYPO3\CMS\Core\Package\Exception\ProtectedPackageKeyException;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Vtinnovations\GuardianTypo3\Application\Contract\Typo3ExtensionStateInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * The production adapter over TYPO3's core {@see PackageManager}. It uses only the
 * supported activate/deactivate API (which persists package state itself, on both
 * TYPO3 13.4 and 14) and translates the platform's own protection checks and
 * failures into Guardian machine reasons. No package-state file is ever written
 * by Guardian directly.
 *
 * The core PackageManager singleton is resolved lazily via GeneralUtility so this
 * adapter constructs cleanly regardless of the container wiring, and reports
 * itself unavailable (never crashes) when the platform API cannot be obtained.
 */
final class Typo3CorePackageService implements Typo3ExtensionStateInterface
{
    private ?PackageManager $packageManager = null;

    public function isAvailable(): bool
    {
        return $this->packageManager() !== null;
    }

    public function isActive(string $extensionKey): bool
    {
        $pm = $this->packageManager();
        if ($pm === null) {
            return false;
        }
        try {
            return $pm->isPackageActive($extensionKey);
        } catch (\Throwable) {
            return false;
        }
    }

    public function isProtected(string $extensionKey): bool
    {
        $pm = $this->packageManager();
        if ($pm === null) {
            return false;
        }
        try {
            if (!$pm->isPackageAvailable($extensionKey)) {
                return false;
            }

            return $pm->getPackage($extensionKey)->isProtected();
        } catch (\Throwable) {
            return false;
        }
    }

    public function deactivate(string $extensionKey): void
    {
        $pm = $this->packageManager();
        if ($pm === null) {
            throw new GuardianException('disable_unavailable');
        }
        if (!$pm->isPackageAvailable($extensionKey)) {
            throw new GuardianException('not_installed');
        }
        try {
            $pm->deactivatePackage($extensionKey);
        } catch (ProtectedPackageKeyException) {
            throw new GuardianException('protected_package');
        } catch (\Throwable) {
            throw new GuardianException('disable_unsupported');
        }
    }

    public function activate(string $extensionKey): void
    {
        $pm = $this->packageManager();
        if ($pm === null) {
            throw new GuardianException('enable_unavailable');
        }
        if (!$pm->isPackageAvailable($extensionKey)) {
            throw new GuardianException('not_installed');
        }
        try {
            $pm->activatePackage($extensionKey);
        } catch (\Throwable) {
            throw new GuardianException('enable_unsupported');
        }
    }

    private function packageManager(): ?PackageManager
    {
        if ($this->packageManager instanceof PackageManager) {
            return $this->packageManager;
        }
        if (!class_exists(PackageManager::class) || !class_exists(GeneralUtility::class)) {
            return null;
        }
        try {
            $this->packageManager = GeneralUtility::makeInstance(PackageManager::class);
        } catch (\Throwable) {
            return null;
        }

        return $this->packageManager;
    }
}
