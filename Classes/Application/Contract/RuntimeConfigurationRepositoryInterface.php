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

use Vtinnovations\GuardianTypo3\Domain\Configuration\RuntimeConfiguration;

/**
 * Persistence port for the {@see RuntimeConfiguration} value object. Keeps the
 * storage format (JSON under var/guardian) out of the domain and application
 * services, which speak only in value objects.
 */
interface RuntimeConfigurationRepositoryInterface
{
    public function load(): RuntimeConfiguration;

    /**
     * Persists a validated configuration and returns the stored (normalised)
     * value object.
     */
    public function save(RuntimeConfiguration $configuration): RuntimeConfiguration;
}
