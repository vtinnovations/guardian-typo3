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

use Vtinnovations\GuardianTypo3\Application\Update\PackageManager;
use Vtinnovations\GuardianTypo3\Domain\Update\PackageName;

/**
 * Inspects an already-extracted, structurally-validated extension in a private
 * staging directory and derives its identity, structure and safety verdict —
 * WITHOUT executing any uploaded PHP. composer.json is parsed as JSON;
 * ext_emconf.php constraints are read with narrow, well-anchored regular
 * expressions only (never include/eval).
 *
 * The result carries a blocking-reason list; when it is non-empty the extension
 * must not be offered for installation.
 */
final class StagedExtensionInspector
{
    private const GUARDIAN_PACKAGE = 'vtinnovations/guardian-typo3';

    public function __construct(
        private readonly PackageManager $packages,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param ?string $filenameHint the original upload filename (e.g.
     *                content_blocks_2.4.8.zip) used only as a version fallback
     */
    public function inspect(string $extractedDir, ?string $filenameHint = null): array
    {
        $reasons = [];
        $root = $this->resolveRoot($extractedDir, $reasons);

        $result = [
            'extension_key' => null,
            'composer_name' => null,
            'version' => null,
            'namespaces' => [],
            'typo3_constraint' => null,
            'php_constraint' => null,
            'dependencies' => [],
            'conflicts' => [],
            'has_composer_json' => false,
            'has_ext_emconf' => false,
            'has_ext_localconf' => false,
            'has_ext_tables' => false,
            'has_services_config' => false,
            'has_backend_routes' => false,
            'has_database_schema' => false,
            'has_public_assets' => false,
            'suspicious_files' => [],
            'legacy' => false,
            'wrapper_generatable' => false,
            'already_installed' => false,
            'target_dir' => null,
            'root_relative' => $root !== null ? ltrim(substr($root, \strlen($extractedDir)), '/') : null,
            'reasons' => [],
            'installable' => false,
        ];

        if ($root === null) {
            $result['reasons'] = array_values(array_unique(array_merge($reasons, ['no_extension_root'])));

            return $result;
        }

        $composer = $this->readComposerJson($root, $reasons);
        $emconf = $this->readEmconf($root);
        $result['has_composer_json'] = $composer !== null;
        $result['has_ext_emconf'] = $emconf !== null;

        $extensionKey = $this->extensionKey($root, $composer, $emconf);
        $result['extension_key'] = $extensionKey;

        // Identity + Composer name.
        if ($composer !== null) {
            $name = \is_string($composer['name'] ?? null) ? strtolower($composer['name']) : null;
            if ($name === null || !PackageName::isValid($name)) {
                $reasons[] = 'invalid_composer_name';
            } else {
                $result['composer_name'] = $name;
            }
            $result['typo3_constraint'] = $this->constraintFromComposer($composer, 'typo3/cms-core');
            $result['php_constraint'] = $this->constraintFromComposer($composer, 'php');
            $result['dependencies'] = $this->composerRequires($composer);
            $result['conflicts'] = array_keys(\is_array($composer['conflict'] ?? null) ? $composer['conflict'] : []);
            $result['namespaces'] = array_keys(\is_array($composer['autoload']['psr-4'] ?? null) ? $composer['autoload']['psr-4'] : []);
        } elseif ($emconf !== null && $extensionKey !== null) {
            // Legacy extension: a safe Composer wrapper (JSON only) can be generated.
            $result['legacy'] = true;
            $result['typo3_constraint'] = $emconf['typo3'];
            $result['php_constraint'] = $emconf['php'];
            $candidate = 'local/' . $extensionKey;
            if (PackageName::isValid($candidate)) {
                $result['composer_name'] = $candidate;
                $result['wrapper_generatable'] = true;
            } else {
                $reasons[] = 'invalid_composer_name';
            }
        } else {
            $reasons[] = 'no_valid_identity';
        }

        // Version detection in priority order: composer.json → ext_emconf.php →
        // (TER/archive metadata, if present) → filename/wrapper (e.g.
        // "content_blocks_2.4.8"). A resolvable stable version is REQUIRED so the
        // isolated Composer dry run can pin the path package instead of dev-main.
        $result['version'] = $this->resolveVersion($composer, $emconf, $filenameHint);
        if ($result['version'] === null && $result['composer_name'] !== null) {
            $reasons[] = 'extension_version_unknown';
        }

        // File inventory.
        $result['has_ext_localconf'] = is_file($root . '/ext_localconf.php');
        $result['has_ext_tables'] = is_file($root . '/ext_tables.php');
        $result['has_services_config'] = is_file($root . '/Configuration/Services.yaml') || is_file($root . '/Configuration/Services.php');
        $result['has_backend_routes'] = is_file($root . '/Configuration/Backend/Routes.php') || is_file($root . '/Configuration/Backend/AjaxRoutes.php');
        $result['has_database_schema'] = is_file($root . '/ext_tables.sql') || $this->hasSqlUnder($root . '/Configuration');
        $result['has_public_assets'] = is_dir($root . '/Resources/Public');
        $result['suspicious_files'] = $this->suspiciousFiles($root);

        // Conflict / safety verdicts.
        $composerName = $result['composer_name'];
        if ($composerName === self::GUARDIAN_PACKAGE) {
            $reasons[] = 'would_overwrite_guardian';
        }
        if ($composerName !== null && (str_starts_with($composerName, 'typo3/cms-') || $composerName === 'typo3/cms')) {
            $reasons[] = 'conflicts_typo3_core';
        }
        if ($composerName !== null && $this->packages->isInstalled($composerName)) {
            $result['already_installed'] = true;
            $reasons[] = 'already_installed';
        }
        if ($result['suspicious_files'] !== []) {
            $reasons[] = 'suspicious_files';
        }
        if ($extensionKey !== null) {
            $result['target_dir'] = $extensionKey;
        }

        $result['reasons'] = array_values(array_unique($reasons));
        $result['installable'] = $result['reasons'] === [] && $result['composer_name'] !== null;

        return $result;
    }

    /**
     * Resolve the single extension root, tolerating ONE wrapper directory. If
     * more than one directory looks like an extension root, that is a rejection.
     */
    private function resolveRoot(string $extractedDir, array &$reasons): ?string
    {
        if (!is_dir($extractedDir)) {
            return null;
        }
        if ($this->looksLikeRoot($extractedDir)) {
            return $extractedDir;
        }
        $entries = array_values(array_filter(scandir($extractedDir) ?: [], static fn (string $e): bool => $e !== '.' && $e !== '..'));
        $dirs = array_values(array_filter($entries, static fn (string $e): bool => is_dir($extractedDir . '/' . $e)));
        $files = array_values(array_filter($entries, static fn (string $e): bool => is_file($extractedDir . '/' . $e)));

        // Exactly one wrapper directory (and no stray top-level files) → descend.
        if (\count($dirs) === 1 && $files === []) {
            $inner = $extractedDir . '/' . $dirs[0];
            if ($this->looksLikeRoot($inner)) {
                return $inner;
            }
        }

        // Multiple candidate roots → unrelated extensions bundled together.
        $roots = array_filter($dirs, fn (string $d): bool => $this->looksLikeRoot($extractedDir . '/' . $d));
        if (\count($roots) > 1) {
            $reasons[] = 'multiple_roots';
        }

        return null;
    }

    private function looksLikeRoot(string $dir): bool
    {
        return is_file($dir . '/composer.json') || is_file($dir . '/ext_emconf.php');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readComposerJson(string $root, array &$reasons): ?array
    {
        $file = $root . '/composer.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!\is_array($data)) {
            $reasons[] = 'invalid_composer_json';

            return null;
        }

        return $data;
    }

    /**
     * Parse ext_emconf.php constraints with anchored regex only — no eval.
     *
     * @return array{version: ?string, typo3: ?string, php: ?string}|null
     */
    private function readEmconf(string $root): ?array
    {
        $file = $root . '/ext_emconf.php';
        if (!is_file($file)) {
            return null;
        }
        $src = (string) @file_get_contents($file);

        $version = null;
        if (preg_match("/['\"]version['\"]\\s*=>\\s*['\"]([0-9][0-9A-Za-z.\\-]{0,30})['\"]/", $src, $m) === 1) {
            $version = $m[1];
        }
        $typo3 = null;
        if (preg_match("/['\"]typo3['\"]\\s*=>\\s*['\"]([0-9][0-9A-Za-z.\\-<>=^~ |*]{0,60})['\"]/", $src, $m) === 1) {
            $typo3 = trim($m[1]);
        }
        $php = null;
        if (preg_match("/['\"]php['\"]\\s*=>\\s*['\"]([0-9][0-9A-Za-z.\\-<>=^~ |*]{0,60})['\"]/", $src, $m) === 1) {
            $php = trim($m[1]);
        }

        return ['version' => $version, 'typo3' => $typo3, 'php' => $php];
    }

    /**
     * Resolve a stable version from the most reliable metadata available.
     *
     * @param array<string, mixed>|null $composer
     * @param array{version: ?string, typo3: ?string, php: ?string}|null $emconf
     */
    private function resolveVersion(?array $composer, ?array $emconf, ?string $filenameHint): ?string
    {
        $candidates = [
            \is_array($composer) && \is_string($composer['version'] ?? null) ? $composer['version'] : null,
            \is_array($emconf) ? ($emconf['version'] ?? null) : null,
            $filenameHint !== null ? $this->versionFromFilename($filenameHint) : null,
        ];
        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeVersion((string) ($candidate ?? ''));
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Extract a version from a filename/wrapper name like "content_blocks_2.4.8"
     * or "content_blocks_2.4.8.zip".
     */
    private function versionFromFilename(string $name): ?string
    {
        $base = preg_replace('/\.zip$/i', '', basename($name)) ?? $name;
        if (preg_match('/(?:^|[_\-])v?(\d+\.\d+(?:\.\d+)?)(?![0-9])/', $base, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Accept only a plausible SemVer-ish version (optionally with a pre-release
     * suffix); reject "dev-main", branch names and empty values.
     */
    private function normalizeVersion(string $version): ?string
    {
        $version = ltrim(trim($version), 'vV');
        if ($version === '' || str_starts_with(strtolower($version), 'dev-')) {
            return null;
        }

        return preg_match('/^\d+\.\d+(?:\.\d+)?(?:[.\-][0-9A-Za-z.\-]+)?$/', $version) === 1 ? $version : null;
    }

    /**
     * @param array<string, mixed>|null $composer
     * @param array{version: ?string, typo3: ?string, php: ?string}|null $emconf
     */
    private function extensionKey(string $root, ?array $composer, ?array $emconf): ?string
    {
        if ($composer !== null) {
            $key = $composer['extra']['typo3/cms']['extension-key'] ?? null;
            if (\is_string($key) && $this->validKey($key)) {
                return $key;
            }
            // Fall back to composer name basename with dashes → underscores.
            if (\is_string($composer['name'] ?? null) && str_contains($composer['name'], '/')) {
                $base = substr($composer['name'], (int) strrpos($composer['name'], '/') + 1);
                $candidate = str_replace('-', '_', strtolower($base));
                if ($this->validKey($candidate)) {
                    return $candidate;
                }
            }
        }
        $base = strtolower(basename($root));
        if ($emconf !== null && $this->validKey($base)) {
            return $base;
        }

        return $this->validKey($base) ? $base : null;
    }

    private function validKey(string $key): bool
    {
        return preg_match('/^[a-z0-9_]{3,60}$/', $key) === 1 && !str_starts_with($key, '_');
    }

    /**
     * @param array<string, mixed> $composer
     */
    private function constraintFromComposer(array $composer, string $package): ?string
    {
        $require = $composer['require'] ?? [];

        return \is_array($require) && isset($require[$package]) && \is_string($require[$package]) ? $require[$package] : null;
    }

    /**
     * @param array<string, mixed> $composer
     * @return list<string>
     */
    private function composerRequires(array $composer): array
    {
        $require = $composer['require'] ?? [];

        return \is_array($require) ? array_values(array_filter(array_keys($require), 'is_string')) : [];
    }

    private function hasSqlUnder(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (strtolower($file->getExtension()) === 'sql') {
                return true;
            }
        }

        return false;
    }

    /**
     * Flags files that do not belong in a normal Composer TYPO3 extension:
     * shell scripts / phars anywhere, and executables placed under public assets.
     *
     * @return list<string>
     */
    private function suspiciousFiles(string $root): array
    {
        $bad = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $rel = ltrim(substr($file->getPathname(), \strlen($root)), '/');
            $ext = strtolower($file->getExtension());
            if (\in_array($ext, ['phar', 'sh', 'bat', 'cmd', 'exe', 'com'], true)) {
                $bad[] = $rel;
                continue;
            }
            if ($ext === 'php' && str_starts_with($rel, 'Resources/Public/')) {
                $bad[] = $rel;
            }
        }

        return array_values(array_slice($bad, 0, 50));
    }
}
