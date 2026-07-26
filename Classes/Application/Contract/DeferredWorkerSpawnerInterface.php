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
 * Spawns a detached CLI worker for a validated job token. Abstracted so the
 * deferred self-maintenance workflow can be unit-tested without launching a real
 * background process.
 */
interface DeferredWorkerSpawnerInterface
{
    /**
     * @throws GuardianException
     */
    public function spawn(string $jobId): void;
}
