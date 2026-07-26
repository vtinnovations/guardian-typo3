<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Lock;

use Vtinnovations\GuardianTypo3\Application\Contract\LockFactoryInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\LockInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * Produces {@see FlockLock} instances whose sentinel files live safely inside
 * Guardian's working directory. Lock names are constrained to a strict pattern
 * so they can never be turned into a path-traversal filename.
 */
final class FlockLockFactory implements LockFactoryInterface
{
    private const NAME_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
    ) {
    }

    public function create(string $name, int $staleSeconds = 1800): LockInterface
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new GuardianException(sprintf('Invalid lock name "%s".', $name));
        }

        $lockFile = $this->workingDirectory->resolve('locks/' . $name . '.lock');

        return new FlockLock($lockFile, max(1, $staleSeconds));
    }
}
