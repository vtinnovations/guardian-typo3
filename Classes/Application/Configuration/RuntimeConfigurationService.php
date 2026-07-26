<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Configuration;

use Vtinnovations\GuardianTypo3\Application\Contract\RuntimeConfigurationRepositoryInterface;
use Vtinnovations\GuardianTypo3\Domain\Configuration\RuntimeConfiguration;

/**
 * Application entry point for reading Guardian's runtime configuration.
 *
 * In Phase 1 the backend Settings section is read-only, so this service exposes
 * only {@see self::current()}. The value object already encapsulates validation
 * and normalisation; a later phase will add a guarded write path (behind admin
 * auth + CSRF) that reuses the same {@see RuntimeConfiguration} rules.
 */
final class RuntimeConfigurationService
{
    public function __construct(
        private readonly RuntimeConfigurationRepositoryInterface $repository,
    ) {
    }

    public function current(): RuntimeConfiguration
    {
        return $this->repository->load();
    }

    /**
     * Persist the recovery-notification settings (enabled + recipients + sender),
     * reusing the value object's own validation/normalisation. Returns the stored
     * normalised configuration.
     */
    public function saveNotifications(bool $enabled, string $recipients, string $sender): RuntimeConfiguration
    {
        return $this->repository->save(
            $this->repository->load()->withNotifications($enabled, $recipients, $sender)
        );
    }

    /**
     * Persist the configured PHP CLI binary path. Returns the stored normalised
     * configuration.
     */
    public function savePhpBinary(string $phpBinary): RuntimeConfiguration
    {
        return $this->repository->save(
            $this->repository->load()->withPhpBinary($phpBinary)
        );
    }
}
