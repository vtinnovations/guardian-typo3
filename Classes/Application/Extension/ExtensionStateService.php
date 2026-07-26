<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Extension;

use Vtinnovations\GuardianTypo3\Application\Contract\SystemLoggerInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\Typo3ExtensionStateInterface;
use Vtinnovations\GuardianTypo3\Application\Update\PackageManager;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Performs the reversible Enable/Disable of a real TYPO3 extension through
 * TYPO3's supported package API. Eligibility (real third-party extension, not
 * core/system/Guardian, not required by another package, not during a running
 * operation) is enforced by {@see PackageManager}; the actual state change is
 * delegated to {@see Typo3ExtensionStateInterface} which uses the platform API —
 * Guardian never edits generated package-state files itself.
 *
 * These are fast, synchronous operations (no Composer, no long job), so they do
 * not use the update-job pipeline; they are still POST + admin + Pro + CSRF +
 * "no operation running" gated at the controller/policy boundary.
 */
final class ExtensionStateService
{
    public function __construct(
        private readonly PackageManager $packages,
        private readonly Typo3ExtensionStateInterface $extensionState,
        private readonly SystemLoggerInterface $logger,
    ) {
    }

    /**
     * @return array{package: string, extension_key: string, active: bool}
     * @throws GuardianException machine reason on failure
     */
    public function disable(string $name): array
    {
        $this->packages->assertDisableable($name);
        $key = $this->packages->requireExtensionKey($name);
        $this->extensionState->deactivate($key);
        $this->logger->info(sprintf('Extension %s (%s) disabled.', $name, $key), 'extensions');

        return ['package' => $name, 'extension_key' => $key, 'active' => false];
    }

    /**
     * @return array{package: string, extension_key: string, active: bool}
     * @throws GuardianException
     */
    public function enable(string $name): array
    {
        $this->packages->assertEnableable($name);
        $key = $this->packages->requireExtensionKey($name);
        $this->extensionState->activate($key);
        $this->logger->info(sprintf('Extension %s (%s) enabled.', $name, $key), 'extensions');

        return ['package' => $name, 'extension_key' => $key, 'active' => true];
    }
}
