<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Dashboard;

use Vtinnovations\GuardianTypo3\Application\Contract\SchedulerIntegrationInterface;
use Vtinnovations\GuardianTypo3\Application\Environment\EnvironmentInspector;
use Vtinnovations\GuardianTypo3\Application\Environment\EntitlementReader;
use Vtinnovations\GuardianTypo3\Domain\Environment\EnvironmentCapabilities;
use Vtinnovations\GuardianTypo3\Domain\Environment\CapabilityTier;
use Vtinnovations\GuardianTypo3\Infrastructure\Version\ExtensionInformation;

/**
 * Aggregates the read-only facts the backend dashboard shows in Phase 1:
 * extension identity, environment capabilities, license standing, and the
 * scheduling mechanism in use. Returns plain arrays suitable for direct
 * assignment to a Fluid view; it performs no writes and no process execution.
 */
final class DashboardService
{
    public function __construct(
        private readonly ExtensionInformation $extensionInformation,
        private readonly EnvironmentInspector $environmentInspector,
        private readonly EntitlementReader $entitlement,
        private readonly SchedulerIntegrationInterface $scheduler,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $capabilities = $this->environmentInspector->inspect();

        return [
            'extension' => $this->extensionInformation->toArray(),
            'environment' => $this->describeEnvironment($capabilities),
            'license' => $this->describeLicense(),
            'scheduler' => [
                'active' => $this->scheduler->isActive(),
                'description' => $this->scheduler->describe(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeEnvironment(EnvironmentCapabilities $capabilities): array
    {
        return [
            'php_version' => $capabilities->phpVersion,
            'typo3_version' => $capabilities->typo3Version,
            'composer_mode' => $capabilities->composerMode,
            'working_directory' => $capabilities->workingDirectory,
            'working_directory_writable' => $capabilities->workingDirectoryWritable,
            'working_directory_exists' => $capabilities->workingDirectoryExists,
            'extension_count' => \count($capabilities->loadedExtensions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeLicense(): array
    {
        $tier = $this->entitlement->grant()->tier;

        return [
            'tier' => $tier->value,
            'is_licensed' => $tier !== CapabilityTier::None,
            'is_pro' => $tier === CapabilityTier::Pro,
        ];
    }
}
