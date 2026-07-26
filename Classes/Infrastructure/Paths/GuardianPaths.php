<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Paths;

use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Filesystem\PathNormalizer;

/**
 * Resolves Guardian's private working directory under the project's var/ path
 * and safely resolves paths within it.
 *
 * The Contao original scattered "$projectDir . '/var/updater/...'" string
 * concatenations across a dozen classes. Here a single component owns the base
 * path (TYPO3's var/ via the environment port) and every derived path is checked
 * for containment with the pure {@see PathNormalizer} — so no caller can craft a
 * name that escapes the directory. It performs no writes; it only computes and
 * validates paths and reports writability.
 */
final class GuardianPaths implements WorkingDirectoryProviderInterface
{
    private const DIRECTORY_NAME = 'guardian';

    private string $basePath;

    public function __construct(
        ProjectEnvironmentInterface $environment,
        private readonly PathNormalizer $pathNormalizer,
    ) {
        $this->basePath = $this->pathNormalizer->normalize(
            rtrim($environment->varPath(), '/') . '/' . self::DIRECTORY_NAME
        );
    }

    public function path(): string
    {
        return $this->basePath;
    }

    public function exists(): bool
    {
        return is_dir($this->basePath);
    }

    public function resolve(string $relativePath): string
    {
        $candidate = $this->pathNormalizer->normalize($this->basePath . '/' . ltrim($relativePath, '/'));

        if (!$this->pathNormalizer->isContained($this->basePath, $candidate)) {
            throw new GuardianException(sprintf(
                'Refusing to resolve "%s": path escapes Guardian working directory.',
                $relativePath
            ));
        }

        return $candidate;
    }

    public function isWritable(): bool
    {
        // Walk up to the nearest existing ancestor and test that. We never
        // create anything here — this is a pure read-only capability probe.
        $path = $this->basePath;
        while ($path !== '' && $path !== '/' && !is_dir($path)) {
            $path = \dirname($path);
        }

        return $path !== '' && is_dir($path) && is_writable($path);
    }
}
