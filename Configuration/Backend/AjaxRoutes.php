<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

use Vtinnovations\GuardianTypo3\Controller\Backend\GuardianAjaxController;

/**
 * Backend AJAX routes for Guardian.
 *
 * TYPO3 backend AJAX routes are automatically CSRF-protected (the generated URL
 * carries a route token) and require an authenticated backend user; the
 * controller additionally asserts administrator rights. License activation and
 * removal are the only state-changing routes in this phase; all other exposed
 * endpoints remain read-only. The route API is identical on TYPO3 13.4 and 14.
 */
return [
    'guardian_license_status' => [
        'path' => '/guardian/license-status',
        'target' => GuardianAjaxController::class . '::licenseStatus',
    ],
    'guardian_license_activate' => [
        'path' => '/guardian/license-activate',
        'target' => GuardianAjaxController::class . '::licenseActivate',
    ],
    'guardian_license_clear' => [
        'path' => '/guardian/license-clear',
        'target' => GuardianAjaxController::class . '::licenseClear',
    ],
    'guardian_packages' => [
        'path' => '/guardian/packages',
        'target' => GuardianAjaxController::class . '::packages',
    ],
    'guardian_backup_options' => [
        'path' => '/guardian/backup-options',
        'target' => GuardianAjaxController::class . '::backupOptions',
    ],
    'guardian_backup_create' => [
        'path' => '/guardian/backup-create',
        'target' => GuardianAjaxController::class . '::backupCreate',
    ],
    'guardian_backup_list' => [
        'path' => '/guardian/backup-list',
        'target' => GuardianAjaxController::class . '::backupList',
    ],
    'guardian_backup_details' => [
        'path' => '/guardian/backup-details',
        'target' => GuardianAjaxController::class . '::backupDetails',
    ],
    'guardian_backup_download' => [
        'path' => '/guardian/backup-download',
        'target' => GuardianAjaxController::class . '::backupDownload',
    ],
    'guardian_backup_delete' => [
        'path' => '/guardian/backup-delete',
        'target' => GuardianAjaxController::class . '::backupDelete',
    ],
    'guardian_backup_test_email' => [
        'path' => '/guardian/backup-test-email',
        'target' => GuardianAjaxController::class . '::testEmail',
    ],
    'guardian_schedule_get' => [
        'path' => '/guardian/schedule-get',
        'target' => GuardianAjaxController::class . '::scheduleGet',
    ],
    'guardian_schedule_save' => [
        'path' => '/guardian/schedule-save',
        'target' => GuardianAjaxController::class . '::scheduleSave',
    ],
    'guardian_schedule_run' => [
        'path' => '/guardian/schedule-run',
        'target' => GuardianAjaxController::class . '::scheduleRun',
    ],
    'guardian_runtime_get' => [
        'path' => '/guardian/runtime-get',
        'target' => GuardianAjaxController::class . '::runtimeGet',
    ],
    'guardian_notifications_save' => [
        'path' => '/guardian/notifications-save',
        'target' => GuardianAjaxController::class . '::notificationsSave',
    ],
    'guardian_notifications_test' => [
        'path' => '/guardian/notifications-test',
        'target' => GuardianAjaxController::class . '::notificationsTest',
    ],
    'guardian_php_detect' => [
        'path' => '/guardian/php-detect',
        'target' => GuardianAjaxController::class . '::phpDetect',
    ],
    'guardian_php_test' => [
        'path' => '/guardian/php-test',
        'target' => GuardianAjaxController::class . '::phpTest',
    ],
    'guardian_php_save' => [
        'path' => '/guardian/php-save',
        'target' => GuardianAjaxController::class . '::phpSave',
    ],
    'guardian_panel_get' => [
        'path' => '/guardian/panel-get',
        'target' => GuardianAjaxController::class . '::panelGet',
    ],
    'guardian_panel_status' => [
        'path' => '/guardian/panel-status',
        'target' => GuardianAjaxController::class . '::panelStatus',
    ],
    'guardian_panel_save_filename' => [
        'path' => '/guardian/panel-save-filename',
        'target' => GuardianAjaxController::class . '::panelSaveFilename',
    ],
    'guardian_panel_deploy' => [
        'path' => '/guardian/panel-deploy',
        'target' => GuardianAjaxController::class . '::panelDeploy',
    ],
    'guardian_panel_disable' => [
        'path' => '/guardian/panel-disable',
        'target' => GuardianAjaxController::class . '::panelDisable',
    ],
    'guardian_panel_token_generate' => [
        'path' => '/guardian/panel-token-generate',
        'target' => GuardianAjaxController::class . '::panelTokenGenerate',
    ],
    'guardian_panel_rotate' => [
        'path' => '/guardian/panel-rotate',
        'target' => GuardianAjaxController::class . '::panelRotate',
    ],
    'guardian_panel_test' => [
        'path' => '/guardian/panel-test',
        'target' => GuardianAjaxController::class . '::panelTest',
    ],
    'guardian_recovery_list' => [
        'path' => '/guardian/recovery-list',
        'target' => GuardianAjaxController::class . '::recoveryList',
    ],
    'guardian_recovery_preflight' => [
        'path' => '/guardian/recovery-preflight',
        'target' => GuardianAjaxController::class . '::recoveryPreflight',
    ],
    'guardian_recovery_run' => [
        'path' => '/guardian/recovery-run',
        'target' => GuardianAjaxController::class . '::recoveryRun',
    ],
    'guardian_recovery_history' => [
        'path' => '/guardian/recovery-history',
        'target' => GuardianAjaxController::class . '::recoveryHistory',
    ],
    'guardian_recovery_dry_run' => [
        'path' => '/guardian/recovery-dry-run',
        'target' => GuardianAjaxController::class . '::recoveryDryRun',
    ],
    'guardian_recovery_interrupted' => [
        'path' => '/guardian/recovery-interrupted',
        'target' => GuardianAjaxController::class . '::recoveryInterrupted',
    ],
    'guardian_recovery_rollback_interrupted' => [
        'path' => '/guardian/recovery-rollback-interrupted',
        'target' => GuardianAjaxController::class . '::recoveryRollbackInterrupted',
    ],
    'guardian_update_packages' => [
        'path' => '/guardian/update-packages',
        'target' => GuardianAjaxController::class . '::updatePackages',
    ],
    'guardian_update_check' => [
        'path' => '/guardian/update-check',
        'target' => GuardianAjaxController::class . '::updateCheck',
    ],
    'guardian_update_dry_run' => [
        'path' => '/guardian/update-dry-run',
        'target' => GuardianAjaxController::class . '::updateDryRun',
    ],
    'guardian_update_start' => [
        'path' => '/guardian/update-start',
        'target' => GuardianAjaxController::class . '::updateStart',
    ],
    'guardian_update_job_status' => [
        'path' => '/guardian/update-job-status',
        'target' => GuardianAjaxController::class . '::updateJobStatus',
    ],
    'guardian_update_job_log' => [
        'path' => '/guardian/update-job-log',
        'target' => GuardianAjaxController::class . '::updateJobLog',
    ],
    'guardian_update_jobs' => [
        'path' => '/guardian/update-jobs',
        'target' => GuardianAjaxController::class . '::updateJobs',
    ],
    'guardian_update_job_details' => [
        'path' => '/guardian/update-job-details',
        'target' => GuardianAjaxController::class . '::updateJobDetails',
    ],
    'guardian_update_rollback' => [
        'path' => '/guardian/update-rollback',
        'target' => GuardianAjaxController::class . '::updateRollback',
    ],
    'guardian_update_config_test' => [
        'path' => '/guardian/update-config-test',
        'target' => GuardianAjaxController::class . '::updateConfigTest',
    ],
    'guardian_dashboard_packages' => [
        'path' => '/guardian/dashboard-packages',
        'target' => GuardianAjaxController::class . '::dashboardPackages',
    ],
    'guardian_package_update_dry_run' => [
        'path' => '/guardian/package-update-dry-run',
        'target' => GuardianAjaxController::class . '::packageUpdateDryRun',
    ],
    'guardian_package_update_start' => [
        'path' => '/guardian/package-update-start',
        'target' => GuardianAjaxController::class . '::packageUpdateStart',
    ],
    'guardian_package_remove_dry_run' => [
        'path' => '/guardian/package-remove-dry-run',
        'target' => GuardianAjaxController::class . '::packageRemoveDryRun',
    ],
    'guardian_package_remove_start' => [
        'path' => '/guardian/package-remove-start',
        'target' => GuardianAjaxController::class . '::packageRemoveStart',
    ],
    'guardian_package_disable' => [
        'path' => '/guardian/package-disable',
        'target' => GuardianAjaxController::class . '::packageDisable',
    ],
    'guardian_package_enable' => [
        'path' => '/guardian/package-enable',
        'target' => GuardianAjaxController::class . '::packageEnable',
    ],
    'guardian_ter_search' => [
        'path' => '/guardian/ter-search',
        'target' => GuardianAjaxController::class . '::terSearch',
    ],
    'guardian_ter_analyse' => [
        'path' => '/guardian/ter-analyse',
        'target' => GuardianAjaxController::class . '::terAnalyse',
    ],
    'guardian_ter_install_dry_run' => [
        'path' => '/guardian/ter-install-dry-run',
        'target' => GuardianAjaxController::class . '::terInstallDryRun',
    ],
    'guardian_ter_install_start' => [
        'path' => '/guardian/ter-install-start',
        'target' => GuardianAjaxController::class . '::terInstallStart',
    ],
    'guardian_upload_extension' => [
        'path' => '/guardian/upload-extension',
        'target' => GuardianAjaxController::class . '::uploadExtension',
    ],
    'guardian_upload_inspect' => [
        'path' => '/guardian/upload-inspect',
        'target' => GuardianAjaxController::class . '::uploadInspect',
    ],
    'guardian_custom_dry_run' => [
        'path' => '/guardian/custom-dry-run',
        'target' => GuardianAjaxController::class . '::customDryRun',
    ],
    'guardian_custom_install_start' => [
        'path' => '/guardian/custom-install-start',
        'target' => GuardianAjaxController::class . '::customInstallStart',
    ],
    'guardian_upload_cleanup' => [
        'path' => '/guardian/upload-cleanup',
        'target' => GuardianAjaxController::class . '::uploadCleanup',
    ],
    'guardian_custom_orphan_remove' => [
        'path' => '/guardian/custom-orphan-remove',
        'target' => GuardianAjaxController::class . '::customOrphanRemove',
    ],
    'guardian_self_disable' => [
        'path' => '/guardian/self-disable',
        'target' => GuardianAjaxController::class . '::guardianSelfDisable',
    ],
    'guardian_self_status' => [
        'path' => '/guardian/self-status',
        'target' => GuardianAjaxController::class . '::guardianSelfStatus',
    ],
    'guardian_uninstall_dry_run' => [
        'path' => '/guardian/uninstall-dry-run',
        'target' => GuardianAjaxController::class . '::guardianUninstallDryRun',
    ],
    'guardian_uninstall' => [
        'path' => '/guardian/uninstall',
        'target' => GuardianAjaxController::class . '::guardianUninstall',
    ],
    'guardian_analyse' => [
        'path' => '/guardian/analyse',
        'target' => GuardianAjaxController::class . '::analyse',
    ],
    'guardian_job_status' => [
        'path' => '/guardian/job-status',
        'target' => GuardianAjaxController::class . '::jobStatus',
    ],
    'guardian_job_archive' => [
        'path' => '/guardian/job-archive',
        'target' => GuardianAjaxController::class . '::jobArchive',
    ],
];
