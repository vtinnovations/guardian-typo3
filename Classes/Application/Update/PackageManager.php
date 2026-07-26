<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Update;

use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\Typo3ExtensionStateInterface;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;
use Vtinnovations\GuardianTypo3\Domain\Update\PackageClassification;
use Vtinnovations\GuardianTypo3\Domain\Update\PackageName;
use Vtinnovations\GuardianTypo3\Infrastructure\Composer\PathRepositoryInspector;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\UpdateJobStore;

/**
 * Read model + policy for the Extensions "manage installed packages" feature.
 *
 * It reads vendor/composer/installed.json and the root composer.json to describe
 * every installed package with a PRECISE classification (TYPO3 core / system
 * extension / third-party extension / local path extension / ordinary Composer
 * library) and a dependency role (root require vs transitive), then decides —
 * SERVER-SIDE — which of Update / Disable / Enable / Remove are even APPLICABLE
 * for that class of package and, when applicable, whether they are permitted and
 * why not.
 *
 * The distinction between "applicable" and "permitted" is what removes the
 * meaningless disabled buttons: an ordinary library is never *applicable* for
 * Disable, and a transitive dependency is never *applicable* for Remove, so the
 * UI does not render those controls at all. A control is rendered disabled with a
 * reason only when it is applicable to that package class but cannot run right now.
 *
 * Safety rules (conservative, Composer-mode reality):
 *   - TYPO3 core + system extensions are never individually updated/removed/disabled.
 *   - Guardian never disables, removes or self-updates itself.
 *   - Only ROOT (composer.json "require") packages are removable, and only when
 *     nothing else still depends on them.
 *   - Enable/Disable use TYPO3's supported package API and apply only to real,
 *     non-protected third-party TYPO3 extensions.
 *   - Any action is blocked while a Guardian operation is running.
 */
final class PackageManager
{
    private const GUARDIAN_PACKAGE = 'vtinnovations/guardian-typo3';

    public function __construct(
        private readonly ProjectEnvironmentInterface $environment,
        private readonly PathRepositoryInspector $pathRepositories,
        private readonly UpdateJobStore $jobStore,
        private readonly Typo3ExtensionStateInterface $extensionState,
    ) {
    }

    /**
     * @param array<string, array{latest?: string, latest_overall?: string, has_update?: bool, update_state?: string}> $updateMap
     * @param ?string $updateError machine error code when the update check failed
     * @return array{operationInProgress: bool, updateMetadata: string, packages: list<array<string, mixed>>}
     */
    public function list(array $updateMap = [], ?string $updateError = null): array
    {
        $installed = $this->readInstalled();
        $rootRequire = $this->rootRequire();
        $pathPackages = $this->pathRepositoryNames();
        $dependedUpon = $this->reverseDependencies($installed);
        $busy = $this->operationInProgress();
        $metadataLoaded = $updateMap !== [] || $updateError !== null;

        $packages = [];
        foreach ($installed as $pkg) {
            $name = $pkg['name'];
            $type = (string) ($pkg['type'] ?? 'library');
            $isPath = isset($pathPackages[$name]);
            $isRoot = isset($rootRequire[$name]);
            $isGuardian = $name === self::GUARDIAN_PACKAGE;
            $isCore = $this->isCore($name, $type);
            $isSystem = $this->isSystemExtension($name, $type);
            $isExtension = $type === 'typo3-cms-extension';
            $extensionKey = $this->extensionKey($name, $type, $pkg['extra'] ?? null);
            $category = $this->categoryOf($isCore, $isSystem, $isExtension, $isPath);
            $classification = $this->coarseClassification($isCore, $isSystem, $isPath, $type, $name);

            $active = true;
            if ($isExtension && $extensionKey !== null && $this->extensionState->isAvailable()) {
                $active = $this->extensionState->isActive($extensionKey);
            }

            $meta = $updateMap[$name] ?? [];
            $updateState = $this->updateState($meta, $metadataLoaded, $updateError, $isCore || $isSystem);
            $hasUpdate = ($meta['has_update'] ?? false) === true && !$isCore && !$isSystem;

            $packages[] = [
                'name' => $name,
                'extension_key' => $extensionKey,
                'current' => ltrim((string) ($pkg['version'] ?? ''), 'v'),
                'latest' => (string) ($meta['latest'] ?? ''),
                'latest_overall' => (string) ($meta['latest_overall'] ?? ($meta['latest'] ?? '')),
                'constraint' => (string) ($rootRequire[$name] ?? ''),
                'has_update' => $hasUpdate,
                'update_state' => $updateState,
                'type' => $type,
                'source' => $isPath ? 'local-path' : 'composer',
                'category' => $category,
                'classification' => $classification->value,
                'composer_managed' => true,
                'is_extension' => $isExtension,
                'is_system_extension' => $isSystem,
                'is_core' => $isCore,
                'is_root' => $isRoot,
                'is_transitive' => !$isRoot,
                'is_guardian' => $isGuardian,
                'active' => $active,
                'state' => $active ? 'active' : 'disabled',
                'abandoned' => isset($pkg['abandoned']),
                'auto_updatable' => $hasUpdate && !$isCore && !$isSystem && !$isGuardian,
                'actions' => $this->actionsFor($name, [
                    'is_core' => $isCore,
                    'is_system' => $isSystem,
                    'is_extension' => $isExtension,
                    'is_guardian' => $isGuardian,
                    'is_root' => $isRoot,
                    'active' => $active,
                    'has_update' => $hasUpdate,
                    'depended_upon' => isset($dependedUpon[$name]),
                    'extension_key' => $extensionKey,
                ], $busy),
            ];
        }

        return [
            'operationInProgress' => $busy,
            'updateMetadata' => $updateError !== null ? 'failed' : ($metadataLoaded ? 'loaded' : 'unavailable'),
            'packages' => $packages,
        ];
    }

    // ── server-side action gates (throw the precise reason) ───────────────────

    /**
     * @throws GuardianException with a machine reason code
     */
    public function assertUpdatable(string $name): void
    {
        $name = $this->requireInstalled($name);
        $this->assertNotBusy();
        if ($name === self::GUARDIAN_PACKAGE) {
            throw new GuardianException('guardian_self');
        }
        $type = $this->typeOf($name);
        if ($this->isCore($name, $type) || $this->isSystemExtension($name, $type)) {
            throw new GuardianException('core_update_use_full');
        }
    }

    /**
     * @throws GuardianException
     */
    public function assertRemovable(string $name): void
    {
        $name = $this->requireInstalled($name);
        $this->assertNotBusy();
        if ($name === self::GUARDIAN_PACKAGE) {
            throw new GuardianException('guardian_self');
        }
        $type = $this->typeOf($name);
        if ($this->isCore($name, $type)) {
            throw new GuardianException('core_cannot_remove');
        }
        if ($this->isSystemExtension($name, $type)) {
            throw new GuardianException('system_cannot_remove');
        }
        if (!isset($this->rootRequire()[$name])) {
            throw new GuardianException('transitive_dependency');
        }
        if (isset($this->reverseDependencies($this->readInstalled())[$name])) {
            throw new GuardianException('required_by_other');
        }
    }

    /**
     * @throws GuardianException
     */
    public function assertDisableable(string $name): void
    {
        $name = $this->requireInstalled($name);
        $this->assertNotBusy();
        if ($name === self::GUARDIAN_PACKAGE) {
            throw new GuardianException('guardian_self');
        }
        $type = $this->typeOf($name);
        if ($this->isCore($name, $type) || $this->isSystemExtension($name, $type)) {
            throw new GuardianException('core_cannot_disable');
        }
        if ($type !== 'typo3-cms-extension') {
            throw new GuardianException('not_an_extension');
        }
        if (!$this->extensionState->isAvailable()) {
            throw new GuardianException('disable_unavailable');
        }
        $key = $this->requireExtensionKey($name);
        if ($this->extensionState->isProtected($key)) {
            throw new GuardianException('protected_package');
        }
        if (!$this->extensionState->isActive($key)) {
            throw new GuardianException('already_disabled');
        }
        if (isset($this->reverseDependencies($this->readInstalled())[$name])) {
            throw new GuardianException('required_by_other');
        }
    }

    /**
     * @throws GuardianException
     */
    public function assertEnableable(string $name): void
    {
        $name = $this->requireInstalled($name);
        $this->assertNotBusy();
        if ($name === self::GUARDIAN_PACKAGE) {
            throw new GuardianException('guardian_self');
        }
        $type = $this->typeOf($name);
        if ($type !== 'typo3-cms-extension') {
            throw new GuardianException('not_an_extension');
        }
        if (!$this->extensionState->isAvailable()) {
            throw new GuardianException('enable_unavailable');
        }
        $key = $this->requireExtensionKey($name);
        if ($this->extensionState->isActive($key)) {
            throw new GuardianException('already_active');
        }
    }

    /**
     * Whether the package is a DIRECT Composer root requirement (composer.json
     * "require"). Used to confirm Guardian can be removed with `composer remove`.
     */
    public function isRootRequirement(string $name): bool
    {
        return isset($this->rootRequire()[$name]);
    }

    public function isInstalled(string $name): bool
    {
        foreach ($this->readInstalled() as $pkg) {
            if ($pkg['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * The extension key for an installed TYPO3 extension (for enable/disable).
     *
     * @throws GuardianException
     */
    public function requireExtensionKey(string $name): string
    {
        foreach ($this->readInstalled() as $pkg) {
            if ($pkg['name'] === $name) {
                $key = $this->extensionKey($name, (string) ($pkg['type'] ?? 'library'), $pkg['extra'] ?? null);
                if ($key === null) {
                    throw new GuardianException('not_an_extension');
                }

                return $key;
            }
        }

        throw new GuardianException('not_installed');
    }

    // ── internals ─────────────────────────────────────────────────────────────

    /**
     * @param array{is_core: bool, is_system: bool, is_extension: bool, is_guardian: bool, is_root: bool, active: bool, has_update: bool, depended_upon: bool, extension_key: ?string} $ctx
     * @return array{update: array<string, mixed>, disable: array<string, mixed>, enable: array<string, mixed>, remove: array<string, mixed>}
     */
    private function actionsFor(string $name, array $ctx, bool $busy): array
    {
        // Which actions even make sense for this class of package.
        $updateApplicable = $ctx['has_update'] && !$ctx['is_core'] && !$ctx['is_system'] && !$ctx['is_guardian'];
        $extApiUsable = $ctx['is_extension'] && !$ctx['is_guardian'] && $ctx['extension_key'] !== null && $this->extensionState->isAvailable();
        $disableApplicable = $extApiUsable && $ctx['active'];
        $enableApplicable = $extApiUsable && !$ctx['active'];
        $removeApplicable = $ctx['is_root'] && !$ctx['is_core'] && !$ctx['is_system'] && !$ctx['is_guardian'];

        return [
            'update' => $this->gate($updateApplicable, fn () => $this->assertUpdatable($name), $busy),
            'disable' => $this->gate($disableApplicable, fn () => $this->assertDisableable($name), $busy),
            'enable' => $this->gate($enableApplicable, fn () => $this->assertEnableable($name), $busy),
            'remove' => $this->gate($removeApplicable, fn () => $this->assertRemovable($name), $busy),
        ];
    }

    /**
     * @return array{applicable: bool, permitted: bool, reason: ?string}
     */
    private function gate(bool $applicable, callable $assert, bool $busy): array
    {
        if (!$applicable) {
            return ['applicable' => false, 'permitted' => false, 'reason' => null];
        }
        if ($busy) {
            return ['applicable' => true, 'permitted' => false, 'reason' => 'operation_in_progress'];
        }
        try {
            $assert();

            return ['applicable' => true, 'permitted' => true, 'reason' => null];
        } catch (GuardianException $e) {
            return ['applicable' => true, 'permitted' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * @param array{latest?: string, has_update?: bool, update_state?: string} $meta
     */
    private function updateState(array $meta, bool $metadataLoaded, ?string $updateError, bool $coreOrSystem): string
    {
        if ($updateError !== null) {
            return 'update_check_failed';
        }
        if (!$metadataLoaded) {
            return 'metadata_unavailable';
        }
        if (isset($meta['update_state']) && \is_string($meta['update_state']) && $meta['update_state'] !== '') {
            return $meta['update_state'];
        }

        return ($meta['has_update'] ?? false) === true ? 'update_available' : 'up_to_date';
    }

    private function categoryOf(bool $isCore, bool $isSystem, bool $isExtension, bool $isPath): string
    {
        if ($isCore) {
            return 'typo3_core';
        }
        if ($isSystem) {
            return 'typo3_system_extension';
        }
        if ($isExtension) {
            return $isPath ? 'local_extension' : 'third_party_extension';
        }

        return 'composer_library';
    }

    private function coarseClassification(bool $isCore, bool $isSystem, bool $isPath, string $type, string $name): PackageClassification
    {
        if ($isCore || $isSystem) {
            return PackageClassification::Core;
        }
        if ($isPath) {
            return PackageClassification::Custom;
        }

        return PackageClassification::classify($name, $type, false);
    }

    private function isCore(string $name, string $type): bool
    {
        $lower = strtolower($name);

        return $lower === 'typo3/cms' || $lower === 'typo3/cms-core';
    }

    private function isSystemExtension(string $name, string $type): bool
    {
        $lower = strtolower($name);
        if ($lower === 'typo3/cms' || $lower === 'typo3/cms-core') {
            return false; // that is core, not a "system extension" row
        }

        return $type === 'typo3-cms-framework' || str_starts_with($lower, 'typo3/cms-');
    }

    private function assertNotBusy(): void
    {
        if ($this->operationInProgress()) {
            throw new GuardianException('operation_in_progress');
        }
    }

    private function operationInProgress(): bool
    {
        $job = $this->jobStore->current();

        return $job !== null && !$job->isFinished() && !$this->jobStore->isStale($job);
    }

    private function requireInstalled(string $name): string
    {
        $name = PackageName::fromString($name)->value;
        if (!$this->isInstalled($name)) {
            throw new GuardianException('not_installed');
        }

        return $name;
    }

    private function typeOf(string $name): string
    {
        foreach ($this->readInstalled() as $pkg) {
            if ($pkg['name'] === $name) {
                return (string) ($pkg['type'] ?? 'library');
            }
        }

        return 'library';
    }

    /**
     * @param mixed $extra
     */
    private function extensionKey(string $name, string $type, mixed $extra): ?string
    {
        if ($type !== 'typo3-cms-extension') {
            return null;
        }
        if (\is_array($extra) && isset($extra['typo3/cms']['extension-key']) && \is_string($extra['typo3/cms']['extension-key'])) {
            return $extra['typo3/cms']['extension-key'];
        }
        $base = substr($name, (int) strrpos($name, '/') + 1);

        return str_replace('-', '_', $base);
    }

    /**
     * @return list<array{name: string, version?: string, type?: string, extra?: mixed, require?: mixed, abandoned?: mixed}>
     */
    private function readInstalled(): array
    {
        $file = rtrim($this->environment->projectPath(), '/') . '/vendor/composer/installed.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!\is_array($data)) {
            return [];
        }
        $packages = $data['packages'] ?? $data;
        $out = [];
        foreach (\is_array($packages) ? $packages : [] as $pkg) {
            if (\is_array($pkg) && !empty($pkg['name'])) {
                $pkg['name'] = (string) $pkg['name'];
                $out[] = $pkg;
            }
        }

        return $out;
    }

    /**
     * @return array<string, string> root require map (name => constraint)
     */
    private function rootRequire(): array
    {
        $file = rtrim($this->environment->projectPath(), '/') . '/composer.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);
        $require = \is_array($data) ? ($data['require'] ?? []) : [];

        return \is_array($require) ? $require : [];
    }

    /**
     * @param list<array<string, mixed>> $installed
     * @return array<string, true> names required by at least one OTHER installed package
     */
    private function reverseDependencies(array $installed): array
    {
        $depended = [];
        foreach ($installed as $pkg) {
            $require = $pkg['require'] ?? [];
            if (!\is_array($require)) {
                continue;
            }
            foreach (array_keys($require) as $dep) {
                if (\is_string($dep) && $dep !== ($pkg['name'] ?? '')) {
                    $depended[$dep] = true;
                }
            }
        }

        return $depended;
    }

    /**
     * @return array<string, true>
     */
    private function pathRepositoryNames(): array
    {
        $project = rtrim($this->environment->projectPath(), '/');
        $vendor = $project . '/vendor';
        $names = [];
        foreach ($this->pathRepositories->inspect($vendor, $vendor, $project) as $repo) {
            $names[(string) $repo['package']] = true;
        }

        return $names;
    }
}
