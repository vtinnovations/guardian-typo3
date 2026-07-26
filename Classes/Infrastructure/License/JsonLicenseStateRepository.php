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

use Vtinnovations\GuardianTypo3\Application\Contract\LicenseStateRepositoryInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseState;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseValidationStatus;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Reads the cached license verification result from <var>/guardian/license.json.
 *
 * Missing, unreadable, malformed or structurally invalid state fails closed.
 * Writes use a same-directory temporary file and atomic rename.
 */
final class JsonLicenseStateRepository implements LicenseStateRepositoryInterface
{
    private const FILENAME = 'license.json';

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    public function load(): LicenseState
    {
        $file = $this->workingDirectory->resolve(self::FILENAME);
        if (!is_file($file)) {
            return LicenseState::unlicensed();
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            return LicenseState::unlicensed();
        }

        $data = json_decode($raw, true);

        if (!\is_array($data) || !$this->isValidData($data)) {
            return LicenseState::unlicensed();
        }

        try {
            return LicenseState::fromArray($data);
        } catch (\ValueError|\TypeError) {
            return LicenseState::unlicensed();
        }
    }

    public function save(LicenseState $state): void
    {
        $file = $this->workingDirectory->resolve(self::FILENAME);
        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new GuardianException('Could not create Guardian working directory.');
        }

        $json = json_encode($state->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new GuardianException('Could not encode license state.');
        }

        $tmp = @tempnam($dir, '.license-');
        if ($tmp === false || @file_put_contents($tmp, $json, \LOCK_EX) === false || !@rename($tmp, $file)) {
            if (\is_string($tmp)) {
                @unlink($tmp);
            }
            throw new GuardianException('Could not persist license state.');
        }
        @chmod($file, 0o640);
    }

    public function clear(): void
    {
        $file = $this->workingDirectory->resolve(self::FILENAME);
        if (is_file($file) && !@unlink($file)) {
            throw new GuardianException('Could not remove the stored license.');
        }
    }

    /** @param array<string, mixed> $data */
    private function isValidData(array $data): bool
    {
        foreach (['license_key', 'license_domain', 'license_package', 'validation_status', 'project', 'project_slug', 'signature'] as $field) {
            if (isset($data[$field]) && !\is_string($data[$field])) {
                return false;
            }
        }
        foreach (['license_verified_at', 'license_issued_at', 'license_starts_at', 'license_expires_at', 'license_version', 'schema_version'] as $field) {
            if (isset($data[$field]) && !\is_int($data[$field]) && !(\is_string($data[$field]) && ctype_digit($data[$field]))) {
                return false;
            }
        }
        if (isset($data['license_lifetime']) && !\is_bool($data['license_lifetime'])) {
            return false;
        }
        if (isset($data['license_features']) && !\is_array($data['license_features'])) {
            return false;
        }
        if (isset($data['validation_status']) && LicenseValidationStatus::tryFrom($data['validation_status']) === null) {
            return false;
        }

        return !isset($data['license_key']) || strlen($data['license_key']) <= 190;
    }
}
