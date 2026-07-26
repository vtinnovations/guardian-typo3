<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\License;

use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;

/**
 * Reads and decodes the raw license store for the optional signature layer.
 *
 * The authoritative store schema is owned by {@see JsonLicenseStateRepository};
 * this reader only exposes the decoded payload so the signature sentinel can
 * evaluate an issuer signature when one is present, without the application layer
 * touching the filesystem directly.
 */
final class LicenseStoreReader
{
    private const STORE = 'license.json';

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    /**
     * @return array<string, mixed>|null decoded payload, or null when absent/invalid
     */
    public function decoded(): ?array
    {
        $file = $this->workingDirectory->resolve(self::STORE);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return \is_array($data) ? $data : null;
    }
}
