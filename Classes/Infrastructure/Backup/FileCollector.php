<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Backup;

use Vtinnovations\GuardianTypo3\Application\Backup\ComponentSelection;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Domain\Archive\ArchiveEntryValidator;
use Vtinnovations\GuardianTypo3\Domain\Backup\BackupComponent;
use Vtinnovations\GuardianTypo3\Domain\Filesystem\PathNormalizer;

/**
 * Resolves the selected file components to a stream of concrete files to archive.
 *
 * Security properties (ported and hardened from the audited Contao backup path):
 *   - Every source path is resolved server-side from the TYPO3 project layout;
 *     browser values only toggle WHICH components are collected, never paths.
 *   - Directory trees are walked with a low-memory generator (no full tree in
 *     memory), preserving the hierarchy and handling spaces/Unicode names as raw
 *     bytes.
 *   - Symlinks are FOLLOWED only when their real target stays inside the TYPO3
 *     project (e.g. Composer path-repository links such as
 *     vendor/<vendor>/<local-pkg> -> ../../packages/<pkg>), so those packages are
 *     actually captured and the archive is complete; the target's contents are
 *     archived under the link's own path. Symlinks that escape the project, point
 *     into the backups directory, or would create a loop are skipped and recorded.
 *   - A component root that is itself a symlink is only followed when its real
 *     target stays inside the TYPO3 project; otherwise it is skipped.
 *   - Every yielded file is verified to be inside the project and its archive
 *     entry name is validated (no absolute paths, no "..").
 *   - The backups directory is never included (no recursive self-inclusion), and
 *     VCS/build/OS-metadata directories are excluded.
 */
final class FileCollector
{
    private const EXCLUDED_NAMES = ['.git', '.svn', '.hg', 'node_modules', '.DS_Store', '__MACOSX', '.idea', '.gitkeep'];

    /** Belt-and-braces recursion cap in addition to the cycle guard. */
    private const MAX_DEPTH = 60;

    private string $projectPath;

    /** Canonical (realpath) project root, for containment/cycle checks on followed symlinks. */
    private string $projectReal;

    public function __construct(
        private readonly ProjectEnvironmentInterface $environment,
        private readonly BackupStorage $storage,
        private readonly ArchiveEntryValidator $entryValidator,
        private readonly PathNormalizer $pathNormalizer,
    ) {
        $this->projectPath = rtrim($this->pathNormalizer->normalize($this->environment->projectPath()), '/');
        $real = realpath($this->environment->projectPath());
        $this->projectReal = $real !== false ? rtrim($real, '/') : $this->projectPath;
    }

    /**
     * Yields ['abs' => string, 'entry' => string, 'size' => int] for each file
     * to archive. Non-fatal skips (missing dir, escaping symlink) are reported
     * through $log.
     *
     * @param callable(string):void $log
     * @return \Generator<int, array{abs: string, entry: string, size: int}>
     */
    public function collect(ComponentSelection $selection, callable $log): \Generator
    {
        foreach ($selection->selectedComponents() as $component) {
            if ($component->isDatabase()) {
                continue; // handled by the database dumper
            }
            if ($component->isSingleFile()) {
                yield from $this->collectSingleFile($component, $log);
                continue;
            }
            yield from $this->collectDirectory($component, $log);
        }
    }

    /**
     * @param callable(string):void $log
     * @return \Generator<int, array{abs: string, entry: string, size: int}>
     */
    private function collectSingleFile(BackupComponent $component, callable $log): \Generator
    {
        $source = $this->sourcePath($component);
        if ($source === null) {
            return;
        }
        if (is_link($source)) {
            $log('Skipped ' . $component->value . ': is a symlink.');

            return;
        }
        if (!is_file($source) || !$this->isInsideProject($source)) {
            $log('Skipped ' . $component->value . ': not found.');

            return;
        }
        $entry = $this->relativeEntry($source);
        if ($entry === null || !$this->entryValidator->isSafe($entry)) {
            return;
        }
        yield ['abs' => $source, 'entry' => $entry, 'size' => (int) @filesize($source)];
    }

    /**
     * @param callable(string):void $log
     * @return \Generator<int, array{abs: string, entry: string, size: int}>
     */
    private function collectDirectory(BackupComponent $component, callable $log): \Generator
    {
        $source = $this->sourcePath($component);
        if ($source === null) {
            return;
        }
        $prefix = $this->relativeEntry($source);
        if ($prefix === null) {
            $log('Skipped ' . $component->value . ': outside the project.');

            return;
        }

        $walkRoot = $source;
        if (is_link($source)) {
            $real = realpath($source);
            if ($real === false || !$this->isInsideProject($real)) {
                $log('Skipped ' . $component->value . ': symlink escapes the project.');

                return;
            }
            $walkRoot = $real;
        }
        if (!is_dir($walkRoot)) {
            $log('Skipped ' . $component->value . ': directory does not exist.');

            return;
        }

        $rootCanonical = realpath($walkRoot);
        yield from $this->walk($walkRoot, $prefix, $log, [$rootCanonical !== false ? $rootCanonical : $walkRoot], 0);
    }

    /**
     * @param callable(string):void $log
     * @param list<string>          $stack canonical directories on the current descent (last = current dir); cycle guard
     * @return \Generator<int, array{abs: string, entry: string, size: int}>
     */
    private function walk(string $absDir, string $entryPrefix, callable $log, array $stack, int $depth): \Generator
    {
        if ($depth > self::MAX_DEPTH) {
            $log('Skipped (maximum directory depth reached): ' . $entryPrefix);

            return;
        }
        $currentCanonical = $stack === [] ? $absDir : $stack[\count($stack) - 1];
        $handle = @opendir($absDir);
        if ($handle === false) {
            $log('Could not read directory: ' . $entryPrefix);

            return;
        }
        try {
            while (($name = readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                if (\in_array($name, self::EXCLUDED_NAMES, true)) {
                    continue;
                }
                $childAbs = $absDir . '/' . $name;
                $childEntry = $entryPrefix . '/' . $name;

                // Never include Guardian's own backups directory.
                if ($this->storage->contains($childAbs)) {
                    continue;
                }
                if (is_link($childAbs)) {
                    yield from $this->followSymlink($childAbs, $childEntry, $log, $stack, $depth);
                    continue;
                }
                if (is_dir($childAbs)) {
                    // A non-symlinked child's canonical path is parent-canonical + name.
                    yield from $this->walk($childAbs, $childEntry, $log, array_merge($stack, [$currentCanonical . '/' . $name]), $depth + 1);
                    continue;
                }
                if (!is_file($childAbs)) {
                    continue;
                }
                if (!$this->entryValidator->isSafe($childEntry)) {
                    $log('Skipped unsafe entry: ' . $childEntry);
                    continue;
                }
                yield ['abs' => $childAbs, 'entry' => $childEntry, 'size' => (int) @filesize($childAbs)];
            }
        } finally {
            closedir($handle);
        }
    }

    /**
     * Follows an in-project symlink (e.g. a Composer path-repository link) so its
     * real content is captured under the link's own entry path. Skips — with a
     * clear reason — any symlink that escapes the project, points into the backups
     * directory, has a missing target, or would create a filesystem loop.
     *
     * @param callable(string):void $log
     * @param list<string>          $stack real absolute directories on the current descent
     * @return \Generator<int, array{abs: string, entry: string, size: int}>
     */
    private function followSymlink(string $linkAbs, string $entry, callable $log, array $stack, int $depth): \Generator
    {
        $rawTarget = @readlink($linkAbs);
        $arrow = $rawTarget !== false && $rawTarget !== '' ? ' -> ' . $rawTarget : '';
        $real = realpath($linkAbs);

        // Canonical containment: the resolved target must stay inside the project.
        if ($real === false || ($real !== $this->projectReal && !str_starts_with($real . '/', $this->projectReal . '/'))) {
            $log('Skipped symlink (escapes the project): ' . $entry . $arrow);

            return;
        }
        if ($this->storage->contains($real)) {
            $log('Skipped symlink (points into the backups directory): ' . $entry . $arrow);

            return;
        }
        if (is_file($real)) {
            if (!$this->entryValidator->isSafe($entry)) {
                $log('Skipped unsafe entry: ' . $entry);

                return;
            }
            $log('Followed path symlink: ' . $entry . $arrow);
            yield ['abs' => $real, 'entry' => $entry, 'size' => (int) @filesize($real)];

            return;
        }
        if (!is_dir($real)) {
            $log('Skipped symlink (target is missing): ' . $entry . $arrow);

            return;
        }

        // Cycle guard: refuse to follow a link whose target is the current
        // directory or one of its ancestors (which would recurse forever).
        foreach ($stack as $ancestor) {
            if ($real === $ancestor || str_starts_with($ancestor . '/', $real . '/')) {
                $log('Skipped symlink (would cause a loop): ' . $entry . $arrow);

                return;
            }
        }

        $log('Followed path symlink: ' . $entry . $arrow . ' (archiving target contents)');
        yield from $this->walk($real, $entry, $log, array_merge($stack, [$real]), $depth + 1);
    }

    private function sourcePath(BackupComponent $component): ?string
    {
        $project = $this->projectPath;
        $public = rtrim($this->pathNormalizer->normalize($this->environment->publicPath()), '/');

        return match ($component) {
            BackupComponent::ComposerJson => $project . '/composer.json',
            BackupComponent::ComposerLock => $project . '/composer.lock',
            BackupComponent::Vendor => $project . '/vendor',
            BackupComponent::Configuration => $project . '/config',
            BackupComponent::Packages => $project . '/packages',
            BackupComponent::Templates => $project . '/templates',
            BackupComponent::Fileadmin => $public . '/fileadmin',
            BackupComponent::PublicAssets => $public . '/_assets',
            BackupComponent::Database => null,
        };
    }

    private function isInsideProject(string $path): bool
    {
        return $this->pathNormalizer->isContained($this->projectPath, $this->pathNormalizer->normalize($path));
    }

    /**
     * Archive entry name = path relative to the project root (forward slashes),
     * or null if the path is not inside the project.
     */
    private function relativeEntry(string $absolutePath): ?string
    {
        $normalised = $this->pathNormalizer->normalize($absolutePath);
        if (!$this->pathNormalizer->isContained($this->projectPath, $normalised)) {
            return null;
        }
        if ($normalised === $this->projectPath) {
            return '';
        }

        return ltrim(substr($normalised, \strlen($this->projectPath)), '/');
    }
}
