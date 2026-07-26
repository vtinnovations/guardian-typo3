<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Filesystem;

/**
 * Pure, filesystem-free path reasoning.
 *
 * Collapses "." and ".." segments lexically WITHOUT resolving symlinks, then
 * answers containment questions. This is the same defensive approach used in
 * the audited Contao bundle: never trust realpath() alone for a security
 * decision, because a symlink could tunnel a "contained" path out to a
 * forbidden location. Because it touches no I/O it is fully deterministic and
 * unit-testable, and it works for destinations that do not exist yet.
 */
final class PathNormalizer
{
    /**
     * Lexically normalise a path. Multiple slashes collapse, "." is dropped,
     * ".." pops the previous segment. A leading slash is preserved (absolute),
     * and ".." can never escape above the root of an absolute path.
     */
    public function normalize(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $segments = preg_split('#/+#', $path) ?: [];

        $out = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($out !== [] && end($out) !== '..') {
                    array_pop($out);
                } elseif (!$isAbsolute) {
                    // Relative paths may legitimately retain leading "..".
                    $out[] = '..';
                }
                continue;
            }
            $out[] = $segment;
        }

        return ($isAbsolute ? '/' : '') . implode('/', $out);
    }

    /**
     * True when $candidate is the base directory itself or lies strictly
     * inside it, after lexical normalisation of both. Trailing slashes are
     * irrelevant. Neither path is touched on disk.
     */
    public function isContained(string $baseDir, string $candidate): bool
    {
        $base = rtrim($this->normalize($baseDir), '/');
        $target = rtrim($this->normalize($candidate), '/');

        if ($base === '') {
            // An empty base would make everything "contained"; refuse.
            return false;
        }

        return $target === $base || str_starts_with($target, $base . '/');
    }
}
