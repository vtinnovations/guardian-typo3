<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use Vtinnovations\GuardianTypo3\Application\Configuration\RuntimeConfigurationService;
use Vtinnovations\GuardianTypo3\Application\Contract\BackendAuthorizationInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\ProjectEnvironmentInterface;
use Vtinnovations\GuardianTypo3\Application\Environment\EntitlementReader;
use Vtinnovations\GuardianTypo3\Domain\Configuration\ServiceRecord;
use Vtinnovations\GuardianTypo3\Domain\Environment\CapabilityTier;
use Vtinnovations\GuardianTypo3\Infrastructure\Backup\BackupStorage;
use Vtinnovations\GuardianTypo3\Infrastructure\Packages\InstalledPackages;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\EntryNotice;

/**
 * The single backend entry point for Guardian.
 *
 * This is a faithful TYPO3 port of the original Contao Guardian backend module:
 * ONE page with the original five tabs (Dashboard, Update, Backup, Recovery,
 * Einstellungen), switched client-side without a reload. The outer shell is
 * native TYPO3 (ModuleTemplate + document header); everything inside is the
 * ported Guardian interface with the original `.updater-*` structure.
 *
 * The controller only prepares data and wires assets — no business logic. It
 * server-renders the initial license/plan/stat state (so the first paint is
 * correct with no flash) and hands the read-only AJAX endpoint URLs to the
 * client via a JSON config island. TYPO3-specific access is confined here.
 */
final class GuardianModuleController
{
    /**
     * JS config key => backend AJAX route identifier (registered in
     * Configuration/Backend/AjaxRoutes.php).
     *
     * Entitlement is deliberately absent from this map. Entering, updating and
     * removing a licence key belongs to the shared V-T.ONE screen, which is the
     * single place those endpoints are reached from; this module only reads the
     * resulting state and links to that screen.
     *
     * @var array<string, string>
     */
    private const AJAX_ENDPOINTS = [
        'packages' => 'guardian_packages',
        'backupOptions' => 'guardian_backup_options',
        'backupCreate' => 'guardian_backup_create',
        'backupList' => 'guardian_backup_list',
        'backupDetails' => 'guardian_backup_details',
        'backupDelete' => 'guardian_backup_delete',
        'scheduleGet' => 'guardian_schedule_get',
        'scheduleSave' => 'guardian_schedule_save',
        'scheduleRun' => 'guardian_schedule_run',
        'backupTestEmail' => 'guardian_backup_test_email',
        'runtimeGet' => 'guardian_runtime_get',
        'notificationsSave' => 'guardian_notifications_save',
        'notificationsTest' => 'guardian_notifications_test',
        'phpDetect' => 'guardian_php_detect',
        'phpTest' => 'guardian_php_test',
        'phpSave' => 'guardian_php_save',
        'panelGet' => 'guardian_panel_get',
        'panelStatus' => 'guardian_panel_status',
        'panelSaveFilename' => 'guardian_panel_save_filename',
        'panelDeploy' => 'guardian_panel_deploy',
        'panelDisable' => 'guardian_panel_disable',
        'panelTokenGenerate' => 'guardian_panel_token_generate',
        'panelRotate' => 'guardian_panel_rotate',
        'panelTest' => 'guardian_panel_test',
        'recoveryList' => 'guardian_recovery_list',
        'recoveryPreflight' => 'guardian_recovery_preflight',
        'recoveryRun' => 'guardian_recovery_run',
        'recoveryHistory' => 'guardian_recovery_history',
        'recoveryDryRun' => 'guardian_recovery_dry_run',
        'recoveryInterrupted' => 'guardian_recovery_interrupted',
        'recoveryRollbackInterrupted' => 'guardian_recovery_rollback_interrupted',
        'analyse' => 'guardian_analyse',
        'jobStatus' => 'guardian_job_status',
        'jobArchive' => 'guardian_job_archive',
        'updatePackages' => 'guardian_update_packages',
        'updateCheck' => 'guardian_update_check',
        'updateDryRun' => 'guardian_update_dry_run',
        'updateStart' => 'guardian_update_start',
        'updateJobStatus' => 'guardian_update_job_status',
        'updateJobLog' => 'guardian_update_job_log',
        'updateJobs' => 'guardian_update_jobs',
        'updateJobDetails' => 'guardian_update_job_details',
        'updateRollback' => 'guardian_update_rollback',
        'updateConfigTest' => 'guardian_update_config_test',
        'dashboardPackages' => 'guardian_dashboard_packages',
        'packageUpdateDryRun' => 'guardian_package_update_dry_run',
        'packageUpdateStart' => 'guardian_package_update_start',
        'packageRemoveDryRun' => 'guardian_package_remove_dry_run',
        'packageRemoveStart' => 'guardian_package_remove_start',
        'packageDisable' => 'guardian_package_disable',
        'packageEnable' => 'guardian_package_enable',
        'terSearch' => 'guardian_ter_search',
        'terAnalyse' => 'guardian_ter_analyse',
        'terInstallDryRun' => 'guardian_ter_install_dry_run',
        'terInstallStart' => 'guardian_ter_install_start',
        'uploadExtension' => 'guardian_upload_extension',
        'uploadInspect' => 'guardian_upload_inspect',
        'customDryRun' => 'guardian_custom_dry_run',
        'customInstallStart' => 'guardian_custom_install_start',
        'uploadCleanup' => 'guardian_upload_cleanup',
        'customOrphanRemove' => 'guardian_custom_orphan_remove',
        'guardianSelfDisable' => 'guardian_self_disable',
        'guardianSelfStatus' => 'guardian_self_status',
        'guardianUninstallDryRun' => 'guardian_uninstall_dry_run',
        'guardianUninstall' => 'guardian_uninstall',
    ];

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly UriBuilder $uriBuilder,
        private readonly BackendAuthorizationInterface $authorization,
        private readonly EntitlementReader $entitlement,
        private readonly ProjectEnvironmentInterface $environment,
        private readonly InstalledPackages $installedPackages,
        private readonly BackupStorage $backupStorage,
        private readonly RuntimeConfigurationService $runtimeConfiguration,
        private readonly EntryNotice $notice,
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        // Defence in depth: the module is registered admin-only, but assert it
        // in code as well so the guarantee does not depend on routing config.
        $this->authorization->assertAdministrator();

        $grant = $this->entitlement->grant();

        // Entering the module in a signed-in session. The claim inside makes a
        // reload, a second tab or an AJAX call later in the session silent, and
        // delivery is deferred so nothing here waits on it.
        $this->notice->arm($grant, ServiceRecord::PROJECT_SLUG);

        // Two separate questions: manual backup needs any tier in effect, the
        // rest of the product needs Pro. The view and the script are handed
        // both, because the markup and the stylesheet lock different parts of
        // the screen through them.
        $tier = $grant->tier;
        $isPro = $tier === CapabilityTier::Pro;
        $isLicensed = $tier !== CapabilityTier::None;

        $this->pageRenderer->addCssFile('EXT:guardian_typo3/Resources/Public/Css/guardian.css');
        $this->pageRenderer->addJsFile('EXT:guardian_typo3/Resources/Public/JavaScript/guardian.js');
        $this->pageRenderer->addInlineLanguageLabelFile(
            'EXT:guardian_typo3/Resources/Private/Language/locallang.xlf'
        );

        // The backup list itself is populated client-side (one authoritative
        // renderer); only the count is needed for the dashboard stat.
        $backupCount = \count($this->backupStorage->list());

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Guardian');
        $view->assignMultiple([
            'headline' => 'Guardian',
            'isPro' => $isPro,
            'isLicensed' => $isLicensed,
            'planClass' => $isPro ? 'pro' : ($isLicensed ? 'free' : 'none'),
            'typo3Version' => $this->environment->typo3Version(),
            'packageCount' => $this->installedPackages->count(),
            'projectDir' => $this->environment->projectPath(),
            'backupCount' => $backupCount,
            'recoveryPanelFilename' => $this->runtimeConfiguration->current()->recoveryPanelFilename,
            'licensingUrl' => $this->licensingUrl(),
            'configJson' => $this->buildConfigJson($isPro, $isLicensed),
        ]);

        return $view->renderResponse('Guardian/Index');
    }

    /**
     * Where the licence controls actually are. An empty string when the shared
     * screen cannot be addressed, which the templates treat as "render no link"
     * rather than a dead button.
     */
    private function licensingUrl(): string
    {
        try {
            return (string) $this->uriBuilder->buildUriFromRoute('vtone_licensing');
        } catch (RouteNotFoundException) {
            return '';
        }
    }

    private function buildConfigJson(bool $isPro, bool $isLicensed): string
    {
        $endpoints = [];
        $endpointErrors = [];
        foreach (self::AJAX_ENDPOINTS as $key => $routeIdentifier) {
            try {
                // Backend AJAX routes declared in Configuration/Backend/AjaxRoutes.php
                // are exposed by the router under the name "ajax_<identifier>";
                // that prefixed name is what UriBuilder resolves. Building the
                // un-prefixed identifier throws RouteNotFoundException and would
                // silently drop the endpoint — leaving the browser with no URL to
                // call (the root cause of the "service unavailable" message with no
                // request in the Network panel).
                $endpoints[$key] = (string) $this->uriBuilder->buildUriFromRoute('ajax_' . $routeIdentifier);
            } catch (RouteNotFoundException) {
                // Record (non-sensitive) so client diagnostics can report which
                // endpoint could not be built, instead of failing silently.
                $endpointErrors[$key] = 'route_not_found';
            }
        }

        $json = json_encode([
            'pro' => $isPro,
            'licensed' => $isLicensed,
            'licensingUrl' => $this->licensingUrl(),
            'standaloneFilename' => $this->runtimeConfiguration->current()->recoveryPanelFilename,
            'endpoints' => $endpoints,
            'endpointErrors' => $endpointErrors,
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }
}
