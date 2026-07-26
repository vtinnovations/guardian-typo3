<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Maintenance;

use Vtinnovations\GuardianTypo3\Application\Contract\MaintenanceModeInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Guardian's own maintenance mode, implemented as a marker file under
 * var/guardian/maintenance.lock. Replaces the Contao `contao:maintenance-mode`
 * console toggle (TYPO3 has no single equivalent). The marker is honoured by
 * {@see \Vtinnovations\GuardianTypo3\Middleware\MaintenanceMiddleware}, which
 * serves a 503 to frontend visitors while the marker exists — so recovery can
 * take the site offline for the duration of a restore. The backend is never
 * blocked.
 */
final class FileMaintenanceMode implements MaintenanceModeInterface
{
    public const MARKER = 'maintenance.lock';

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    public function enable(): void
    {
        $file = $this->markerPath();
        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new GuardianException('Could not create the Guardian working directory for maintenance mode.');
        }
        if (@file_put_contents($file, gmdate('c') . " Guardian recovery in progress\n", \LOCK_EX) === false) {
            throw new GuardianException('Could not enable maintenance mode.');
        }
        @chmod($file, 0o640);
    }

    public function disable(): void
    {
        $file = $this->markerPath();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function isEnabled(): bool
    {
        return is_file($this->markerPath());
    }

    /**
     * Whether Guardian could toggle maintenance mode (marker dir writable).
     */
    public function isCapable(): bool
    {
        return $this->workingDirectory->isWritable();
    }

    private function markerPath(): string
    {
        return $this->workingDirectory->resolve(self::MARKER);
    }
}
