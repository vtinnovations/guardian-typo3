<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Extension;

use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Update\PackageName;
use Vtinnovations\GuardianTypo3\Infrastructure\Extension\ManagedExtensionRegistry;

/**
 * Applies the Composer path-repository + root-requirement changes needed to
 * install a validated, staged local extension package under packages/ — and,
 * for a dry run, applies them non-destructively and hands back a closure that
 * restores composer.json exactly.
 *
 * It NEVER runs Composer itself (that stays with the shared runner) and never
 * copies anything into vendor/, public/ or fileadmin/. The only live filesystem
 * change is the atomic copy of an already-validated staging directory into
 * packages/<safe-dir> during a real install; composer.json is rewritten as JSON
 * (no PHP is generated from request data).
 *
 * @phpstan-type LocalPackage array{staging_path: string, target_dir: string, composer_name: string}
 */
final class LocalPackagePreparer
{
    public function __construct(
        private readonly ProjectEnvironmentInterface $environment,
        private readonly ManagedExtensionRegistry $registry,
    ) {
    }

    /**
     * Dry-run: point a Composer path repository at the STAGING directory and add
     * the root requirement, without copying anything. Returns a closure that
     * restores the original composer.json byte-for-byte (call it in a finally).
     *
     * @param LocalPackage $local
     */
    public function applyForDryRun(array $local): \Closure
    {
        $composerJson = $this->composerJsonPath();
        $original = (string) @file_get_contents($composerJson);
        if ($original === '') {
            throw new GuardianException('composer.json is unreadable — cannot prepare a local dry run.');
        }
        $staging = $this->requireDirectory($local['staging_path'] ?? '');
        $this->rewriteComposerJson($composerJson, $original, $staging, $this->requireName($local), $this->version($local));

        return static function () use ($composerJson, $original): void {
            @file_put_contents($composerJson, $original, \LOCK_EX);
        };
    }

    /**
     * Real install: copy the validated staging directory atomically into
     * packages/<safe-dir>, then add the path repository (relative) + requirement.
     *
     * @param LocalPackage $local
     */
    public function applyForInstall(array $local): string
    {
        $project = $this->projectPath();
        $target = $this->requireSafeTarget($local);
        $staging = $this->requireDirectory($local['staging_path'] ?? '');

        $packagesDir = $project . '/packages';
        if (!is_dir($packagesDir) && !@mkdir($packagesDir, 0o755, true) && !is_dir($packagesDir)) {
            throw new GuardianException('Could not create the packages/ directory.');
        }
        if (is_dir($target)) {
            // A leftover directory is only reclaimable when Guardian can PROVE it
            // created exactly this path (a managed orphan from a prior install or
            // an interrupted removal). Otherwise never overwrite unknown code.
            if (!$this->registry->ownsDirectory($this->requireName($local), $target)) {
                throw new GuardianException('The target package directory already exists.');
            }
            $this->removeTree($target);
        }

        $this->copyDirectoryAtomic($staging, $target);

        $composerJson = $this->composerJsonPath();
        $original = (string) @file_get_contents($composerJson);
        $relative = 'packages/' . basename($target);
        $this->rewriteComposerJson($composerJson, $original, $relative, $this->requireName($local), $this->version($local));

        return $target;
    }

    /**
     * @param LocalPackage $local
     */
    public function targetPath(array $local): string
    {
        return $this->requireSafeTarget($local);
    }

    private function requireSafeTarget(array $local): string
    {
        $dir = (string) ($local['target_dir'] ?? '');
        if ($dir === '' || preg_match('/^[a-z0-9]([a-z0-9_-]*[a-z0-9])?$/', $dir) !== 1) {
            throw new GuardianException('The local package directory name is invalid.');
        }

        return $this->projectPath() . '/packages/' . $dir;
    }

    private function requireName(array $local): string
    {
        return PackageName::fromString((string) ($local['composer_name'] ?? ''))->value;
    }

    private function version(array $local): string
    {
        return ltrim(trim((string) ($local['version'] ?? '')), 'vV');
    }

    /**
     * True when an existing path repository already serves the given URL — either
     * an exact match or a glob such as "packages/*" that matches it. Used to avoid
     * registering a second, competing canonical path repository for a destination
     * the live composer.json already covers (which would make Composer resolve the
     * package twice and pick "dev-main").
     *
     * A dry-run URL points at the private staging directory (outside packages/),
     * so no "packages/*" glob matches it and a dedicated repo is added; a real
     * install URL is "packages/<dir>", which "packages/*" does cover.
     *
     * @param array<int, mixed> $repositories
     */
    private function repositoryCovers(array $repositories, string $repoUrl): bool
    {
        foreach ($repositories as $repo) {
            if (!\is_array($repo) || ($repo['type'] ?? '') !== 'path') {
                continue;
            }
            $url = (string) ($repo['url'] ?? '');
            if ($url === '') {
                continue;
            }
            if ($url === $repoUrl || fnmatch($url, $repoUrl)) {
                return true;
            }
        }

        return false;
    }

    private function requireDirectory(string $path): string
    {
        if ($path === '' || !is_dir($path)) {
            throw new GuardianException('The staged extension directory is missing.');
        }

        return rtrim($path, '/');
    }

    private function rewriteComposerJson(string $file, string $original, string $repoUrl, string $name, string $version): void
    {
        $data = json_decode($original, true);
        if (!\is_array($data)) {
            throw new GuardianException('composer.json is not valid JSON — refusing to modify it.');
        }

        $repositories = $data['repositories'] ?? [];
        $repositories = \is_array($repositories) ? $repositories : [];

        // Register a path repository ONLY when the destination is not already
        // served by an existing one. A canonical "packages/*" path repository in
        // the live composer.json already covers packages/<dir>; adding a second
        // (also canonical) repository for the same package makes Composer see the
        // package twice and select the higher-priority one — the exact conflict
        // that made it resolve as an unstable "dev-main". When the destination is
        // already covered we rely on that existing repository: the copied
        // package's own composer.json is pinned to the exact stable version (see
        // CustomExtensionInstallService), so it resolves correctly there too.
        if (!$this->repositoryCovers($repositories, $repoUrl)) {
            // canonical + symlink:false copies the package (never links into the
            // live tree) and a pinned STABLE version so Composer resolves the
            // uploaded package as its real version (e.g. 2.4.8) instead of an
            // unstable "dev-main" that minimum-stability would reject.
            $options = ['symlink' => false];
            if ($version !== '') {
                $options['versions'] = [$name => $version];
            }
            $repositories[] = ['type' => 'path', 'url' => $repoUrl, 'canonical' => true, 'options' => $options];
            $data['repositories'] = array_values($repositories);
        }

        $require = $data['require'] ?? [];
        $require = \is_array($require) ? $require : [];
        // Bind the root requirement to the exact version approved during
        // inspection; a wildcard could make Composer select a competing package.
        $require[$name] = $version !== '' ? '=' . $version : '*';
        $data['require'] = $require;

        $encoded = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new GuardianException('Failed to encode the updated composer.json.');
        }
        if (@file_put_contents($file, $encoded . "\n", \LOCK_EX) === false) {
            throw new GuardianException('Failed to write the updated composer.json.');
        }
    }

    /**
     * Copies a validated tree into a fresh sibling temp dir, then renames it into
     * place — so a partially-copied tree is never visible at the target path.
     * Symlinks are refused (defence in depth; the staged tree is already
     * symlink-free from upload validation).
     */
    private function copyDirectoryAtomic(string $source, string $target): void
    {
        $tmp = $target . '.guardian-tmp-' . bin2hex(random_bytes(4));
        $this->copyTree($source, $tmp);
        if (!@rename($tmp, $target)) {
            $this->removeTree($tmp);
            throw new GuardianException('Failed to move the extension into packages/.');
        }
    }

    private function copyTree(string $source, string $target): void
    {
        if (is_link($source)) {
            throw new GuardianException('The staged extension contains a symlink — refusing to copy it.');
        }
        if (!@mkdir($target, 0o755, true) && !is_dir($target)) {
            throw new GuardianException('Failed to create a staging copy directory.');
        }
        $items = scandir($source) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $from = $source . '/' . $item;
            $to = $target . '/' . $item;
            if (is_link($from)) {
                throw new GuardianException('The staged extension contains a symlink — refusing to copy it.');
            }
            if (is_dir($from)) {
                $this->copyTree($from, $to);
            } elseif (!@copy($from, $to)) {
                throw new GuardianException('Failed to copy a staged extension file.');
            }
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function projectPath(): string
    {
        return rtrim($this->environment->projectPath(), '/');
    }

    private function composerJsonPath(): string
    {
        return $this->projectPath() . '/composer.json';
    }
}
