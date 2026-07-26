<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\Recovery;

use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;

/**
 * A real temporary working directory for exercising the recovery-panel stores.
 * It contains its resolved paths inside a per-test base dir, mirroring the
 * production GuardianPaths contract closely enough for filesystem tests.
 */
final class FakeWorkingDirectory implements WorkingDirectoryProviderInterface
{
    public function __construct(private readonly string $base)
    {
        if (!is_dir($this->base)) {
            mkdir($this->base, 0o700, true);
        }
    }

    public function exists(): bool
    {
        return is_dir($this->base);
    }

    public function path(): string
    {
        return $this->base;
    }

    public function resolve(string $relativePath): string
    {
        return rtrim($this->base, '/') . '/' . ltrim($relativePath, '/');
    }

    public function isWritable(): bool
    {
        return is_writable($this->base);
    }
}
