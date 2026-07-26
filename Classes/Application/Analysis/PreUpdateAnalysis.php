<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Analysis;

use Vtinnovations\GuardianTypo3\Application\Contract\DatabaseImporterInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\WorkingDirectoryProviderInterface;
use Vtinnovations\GuardianTypo3\Application\Environment\EnvironmentInspector;
use Vtinnovations\GuardianTypo3\Application\License\LicenseManager;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\ComposerEnvironment;
use Vtinnovations\GuardianTypo3\Infrastructure\Update\UpdateJobStore;

/**
 * Read-only pre-update analysis, ported and expanded from the audited Contao
 * PreUpdateChecker. It verifies every precondition a real update needs without
 * changing anything on disk and without running Composer: Composer mode, the
 * Composer/PHP-CLI binaries, writability of vendor/var, disk space, database
 * connectivity, a valid Pro license, whether a job is already running, and
 * whether a safety backup could be produced.
 *
 * Every label/message is a stable i18n KEY (never localized text), so the JSON
 * stays language-neutral and the backend user's own language is applied in the
 * browser. Each check is classified OK / warning / error; a real update must not
 * start while any blocking error exists. `can_dry_run` is reported separately
 * because a dry run needs fewer preconditions than a real update.
 */
final class PreUpdateAnalysis
{
    public function __construct(
        private readonly ProjectEnvironmentInterface $environment,
        private readonly EnvironmentInspector $environmentInspector,
        private readonly WorkingDirectoryProviderInterface $workingDirectory,
        private readonly ComposerEnvironment $composerEnvironment,
        private readonly DatabaseImporterInterface $databaseImporter,
        private readonly LicenseManager $licenseManager,
        private readonly UpdateJobStore $jobStore,
    ) {
    }

    /**
     * @return array{summary: array{ok: int, warnings: int, errors: int, can_proceed: bool, can_dry_run: bool, message: string}, checks: array<string, array{label: string, status: string, message: string}>}
     */
    public function run(): array
    {
        $caps = $this->environmentInspector->inspect();
        $project = rtrim($this->environment->projectPath(), '/');
        $checks = [];

        $checks['composer_mode'] = $caps->composerMode
            ? $this->check('analysis.composerMode', 'ok', 'analysis.composerMode.ok')
            : $this->check('analysis.composerMode', 'error', 'analysis.composerMode.error');

        $checks['composer_files'] = (is_file($project . '/composer.json') && is_file($project . '/composer.lock'))
            ? $this->check('analysis.composerFiles', 'ok', 'analysis.composerFiles.ok')
            : $this->check('analysis.composerFiles', 'error', 'analysis.composerFiles.error');

        $checks['php_version'] = version_compare($caps->phpVersion, '8.2.0', '>=')
            ? $this->check('analysis.phpVersion', 'ok', 'analysis.phpVersion.ok')
            : $this->check('analysis.phpVersion', 'warning', 'analysis.phpVersion.warning');

        $checks['php_cli'] = $this->composerEnvironment->phpBinary() !== null
            ? $this->check('analysis.phpCli', 'ok', 'analysis.phpCli.ok')
            : $this->check('analysis.phpCli', 'warning', 'analysis.phpCli.warning');

        $checks['composer_binary'] = $this->composerEnvironment->composerBinary() !== null
            ? $this->check('analysis.composerBinary', 'ok', 'analysis.composerBinary.ok')
            : $this->check('analysis.composerBinary', 'warning', 'analysis.composerBinary.warning');

        $checks['console_binary'] = $this->composerEnvironment->typo3Console() !== null
            ? $this->check('analysis.consoleBinary', 'ok', 'analysis.consoleBinary.ok')
            : $this->check('analysis.consoleBinary', 'warning', 'analysis.consoleBinary.warning');

        $checks['working_dir'] = $caps->workingDirectoryWritable
            ? $this->check('analysis.workingDirectory', 'ok', 'analysis.workingDirectory.ok')
            : $this->check('analysis.workingDirectory', 'error', 'analysis.workingDirectory.error');

        $vendorWritable = is_dir($project . '/vendor') && is_writable($project . '/vendor');
        $checks['vendor_writable'] = $vendorWritable
            ? $this->check('analysis.vendorWritable', 'ok', 'analysis.vendorWritable.ok')
            : $this->check('analysis.vendorWritable', 'warning', 'analysis.vendorWritable.warning');

        $checks['disk_space'] = $this->diskSpaceCheck($project);

        $checks['database'] = $this->databaseImporter->canConnect()
            ? $this->check('analysis.database', 'ok', 'analysis.database.ok')
            : $this->check('analysis.database', 'error', 'analysis.database.error');

        $checks['license'] = $this->licenseManager->currentStatus()->pro
            ? $this->check('analysis.license', 'ok', 'analysis.license.ok')
            : $this->check('analysis.license', 'error', 'analysis.license.error');

        $active = $this->jobStore->current();
        $checks['active_job'] = ($active !== null && !$active->isFinished() && !$this->jobStore->isStale($active))
            ? $this->check('analysis.activeJob', 'warning', 'analysis.activeJob.warning')
            : $this->check('analysis.activeJob', 'ok', 'analysis.activeJob.ok');

        $checks['backup_capability'] = ($caps->workingDirectoryWritable && class_exists(\ZipArchive::class))
            ? $this->check('analysis.backupCapability', 'ok', 'analysis.backupCapability.ok')
            : $this->check('analysis.backupCapability', 'warning', 'analysis.backupCapability.warning');

        $ok = $warnings = $errors = 0;
        foreach ($checks as $c) {
            match ($c['status']) {
                'ok' => $ok++,
                'warning' => $warnings++,
                default => $errors++,
            };
        }

        $canDryRun = $checks['composer_mode']['status'] === 'ok'
            && $checks['composer_binary']['status'] === 'ok'
            && $checks['php_cli']['status'] === 'ok'
            && $checks['active_job']['status'] === 'ok';

        $message = $errors > 0
            ? 'analysis.summary.error'
            : ($warnings > 0 ? 'analysis.summary.warning' : 'analysis.summary.ok');

        return [
            'summary' => [
                'ok' => $ok,
                'warnings' => $warnings,
                'errors' => $errors,
                'can_proceed' => $errors === 0,
                'can_dry_run' => $canDryRun,
                'message' => $message,
            ],
            'checks' => $checks,
        ];
    }

    /**
     * @return array{label: string, status: string, message: string}
     */
    private function diskSpaceCheck(string $project): array
    {
        $free = @disk_free_space($project);
        if ($free === false) {
            return $this->check('analysis.diskSpace', 'warning', 'analysis.diskSpace.unknown');
        }

        return $free >= 512 * 1024 * 1024
            ? $this->check('analysis.diskSpace', 'ok', 'analysis.diskSpace.ok')
            : $this->check('analysis.diskSpace', 'warning', 'analysis.diskSpace.warning');
    }

    /**
     * @return array{label: string, status: string, message: string}
     */
    private function check(string $label, string $status, string $message): array
    {
        return ['label' => $label, 'status' => $status, 'message' => $message];
    }
}
