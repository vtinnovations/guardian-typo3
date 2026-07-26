<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Update;

use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Filesystem\PathNormalizer;

/**
 * A PRIVATE, throwaway Composer workspace for installation-analysis dry runs.
 *
 * Composer's `require`/`remove` edit composer.json BEFORE (and even with)
 * `--dry-run`, so running them against the live project mutates it (the reported
 * "/composer.json has been updated"). Guardian instead copies just the manifest
 * (composer.json + composer.lock + auth.json) into
 * <var>/guardian/runtime/analysis/<jobId>/ and runs the analysis THERE, so the
 * live composer.json, composer.lock and vendor/ are never touched.
 *
 * Path-repository URLs are rewritten to absolute paths so dependency resolution
 * still sees the project's local packages without copying them.
 */
final class AnalysisWorkspace
{
    private const DIR = 'runtime/analysis';
    private const ID_PATTERN = '/^\d{8}-\d{6}-[a-f0-9]{8}$/';

    public function __construct(
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
        private readonly ProjectEnvironmentInterface $environment,
        private readonly PathNormalizer $pathNormalizer,
    ) {
    }

    /**
     * Build an isolated copy of the project manifest for the job and return its
     * absolute path. Nothing in the live project is modified.
     *
     * @throws GuardianException
     */
    public function create(string $jobId): string
    {
        $dir = $this->workspaceDir($jobId);
        $this->removeTree($dir);
        if (!@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new GuardianException('analysis_workspace_unavailable');
        }

        $project = rtrim($this->environment->projectPath(), '/');
        $composerJson = $project . '/composer.json';
        if (!is_file($composerJson)) {
            throw new GuardianException('composer_manifest_missing');
        }
        $data = json_decode((string) @file_get_contents($composerJson), true);
        if (!\is_array($data)) {
            throw new GuardianException('composer_manifest_invalid');
        }
        $data = $this->absolutiseRepositories($data, $project);
        if (@file_put_contents($dir . '/composer.json', json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n", \LOCK_EX) === false) {
            throw new GuardianException('analysis_workspace_unwritable');
        }

        // Preserve the current locked set + credentials so resolution is realistic.
        foreach (['composer.lock', 'auth.json'] as $file) {
            if (is_file($project . '/' . $file)) {
                @copy($project . '/' . $file, $dir . '/' . $file);
            }
        }

        return $dir;
    }

    public function cleanup(string $jobId): void
    {
        if (preg_match(self::ID_PATTERN, $jobId) === 1) {
            $this->removeTree($this->workspaceDir($jobId));
        }
    }

    private function workspaceDir(string $jobId): string
    {
        if (preg_match(self::ID_PATTERN, $jobId) !== 1) {
            throw new GuardianException('analysis_workspace_invalid_id');
        }

        return $this->workingDirectory->resolve(self::DIR . '/' . $jobId);
    }

    /**
     * Rewrite relative path-repository URLs to absolute so the temp workspace can
     * still resolve the project's local packages without copying them.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function absolutiseRepositories(array $data, string $project): array
    {
        $repositories = $data['repositories'] ?? null;
        if (!\is_array($repositories)) {
            return $data;
        }
        foreach ($repositories as $key => $repo) {
            if (!\is_array($repo) || ($repo['type'] ?? '') !== 'path' || !\is_string($repo['url'] ?? null)) {
                continue;
            }
            if (!$this->isAbsolute($repo['url'])) {
                $repo['url'] = $this->pathNormalizer->normalize($project . '/' . $repo['url']);
                $repositories[$key] = $repo;
            }
        }
        $data['repositories'] = $repositories;

        return $data;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);

            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
