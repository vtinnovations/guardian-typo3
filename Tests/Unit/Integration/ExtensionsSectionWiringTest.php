<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Structural guarantees for the Extensions section that do not require a TYPO3
 * runtime: the tab is registered, the package block was migrated out of the
 * Dashboard, every new endpoint is Pro-gated, the TER endpoint is fixed and
 * TLS-verified, and the two language overlays keep exact XLF key parity.
 */
final class ExtensionsSectionWiringTest extends TestCase
{
    private function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents($this->root() . '/' . $relative);
    }

    #[Test]
    public function extensionsTabIsRegisteredAndRendered(): void
    {
        self::assertStringContainsString('data-tab="extensions"', $this->read('Resources/Private/Partials/Guardian/Tabs.html'));
        self::assertStringContainsString('partial="Guardian/Extensions"', $this->read('Resources/Private/Templates/Guardian/Index.html'));
    }

    #[Test]
    public function navigationOrderIsDashboardUpdateBackupRecoveryExtensionsSettings(): void
    {
        $tabs = $this->read('Resources/Private/Partials/Guardian/Tabs.html');
        preg_match_all('/data-tab="([a-z]+)"/', $tabs, $m);
        self::assertSame(['dashboard', 'update', 'backup', 'recovery', 'extensions', 'settings'], $m[1]);

        // The JS tab registry keeps Extensions after Recovery and before Settings.
        $js = $this->read('Resources/Public/JavaScript/guardian.js');
        self::assertMatchesRegularExpression("/VALID_TABS\\s*=\\s*\\['dashboard', 'update', 'backup', 'recovery', 'extensions', 'settings'\\]/", $js);
    }

    #[Test]
    public function packageManagementWasMovedOutOfTheDashboard(): void
    {
        $dashboard = $this->read('Resources/Private/Partials/Guardian/Dashboard.html');
        self::assertStringNotContainsString('data-action="packages-load"', $dashboard);
        self::assertStringNotContainsString('id="updaterPkgResult"', $dashboard);

        $extensions = $this->read('Resources/Private/Partials/Guardian/Extensions.html');
        self::assertStringContainsString('data-action="packages-load"', $extensions);
        self::assertStringContainsString('id="updaterPkgResult"', $extensions);
        self::assertStringContainsString('id="guardianTerQuery"', $extensions);
        self::assertStringContainsString('id="guardianUploadFile"', $extensions);
    }

    #[Test]
    public function extensionsTabIsProGatedInJavaScript(): void
    {
        $js = $this->read('Resources/Public/JavaScript/guardian.js');
        self::assertMatchesRegularExpression("/PRO_TABS\\s*=\\s*\\[[^\\]]*'extensions'/", $js);
        self::assertMatchesRegularExpression("/VALID_TABS\\s*=\\s*\\[[^\\]]*'extensions'/", $js);
    }

    #[Test]
    public function allNewAjaxRoutesAreRegistered(): void
    {
        $routes = $this->read('Configuration/Backend/AjaxRoutes.php');
        foreach ([
            'guardian_ter_search', 'guardian_ter_analyse', 'guardian_ter_install_dry_run', 'guardian_ter_install_start',
            'guardian_upload_extension', 'guardian_upload_inspect', 'guardian_custom_dry_run', 'guardian_custom_install_start', 'guardian_upload_cleanup',
        ] as $route) {
            self::assertStringContainsString("'" . $route . "'", $routes);
        }

        $module = $this->read('Classes/Controller/Backend/GuardianModuleController.php');
        self::assertStringContainsString("'terSearch' => 'guardian_ter_search'", $module);
        self::assertStringContainsString("'uploadExtension' => 'guardian_upload_extension'", $module);
    }

    #[Test]
    public function everyExtensionEndpointEnforcesProServerSide(): void
    {
        $controller = $this->read('Classes/Controller/Backend/GuardianAjaxController.php');
        // The installed-extension list is now Pro-only too.
        self::assertMatchesRegularExpression('/function dashboardPackages.*?requirePro/s', $controller);
        // Every new public endpoint calls requirePro().
        foreach ([
            'terSearch', 'terAnalyse', 'uploadExtension', 'uploadInspect', 'uploadCleanup',
        ] as $method) {
            self::assertMatchesRegularExpression('/function ' . $method . '\\(.*?requirePro\\(\\)/s', $controller, $method . ' must enforce Pro');
        }
        // The shared TER/custom install handlers are Pro-gated.
        self::assertMatchesRegularExpression('/function startTerInstall\\(.*?requirePro\\(\\)/s', $controller);
        self::assertMatchesRegularExpression('/function startCustomInstall\\(.*?requirePro\\(\\)/s', $controller);
    }

    #[Test]
    public function terTransportUsesFixedTlsVerifiedEndpointsWithoutRedirects(): void
    {
        $transport = $this->read('Classes/Infrastructure/Ter/RequestFactoryTerTransport.php');
        self::assertStringContainsString("'extensions.typo3.org'", $transport);
        self::assertStringContainsString("'packagist.org'", $transport);
        self::assertStringContainsString("'allow_redirects' => false", $transport);
        self::assertStringContainsString("'verify' => true", $transport);

        // The exact-key endpoint is the real /extension/{key}, not a /search/ path.
        $client = $this->read('Classes/Infrastructure/Ter/TerClient.php');
        self::assertStringContainsString('/api/v1/extension/', $client);
        self::assertStringNotContainsString('/api/v1/search/', $client);
    }

    #[Test]
    public function guardianSelfMaintenanceRoutesAreRegistered(): void
    {
        $routes = $this->read('Configuration/Backend/AjaxRoutes.php');
        foreach (['guardian_self_disable', 'guardian_self_status', 'guardian_uninstall_dry_run', 'guardian_uninstall'] as $route) {
            self::assertStringContainsString("'" . $route . "'", $routes);
        }
        $module = $this->read('Classes/Controller/Backend/GuardianModuleController.php');
        self::assertStringContainsString("'guardianSelfDisable' => 'guardian_self_disable'", $module);
        self::assertStringContainsString("'guardianUninstall' => 'guardian_uninstall'", $module);
    }

    #[Test]
    public function guardianSelfMaintenanceIsSystemMaintainerGatedWithTypedConfirmation(): void
    {
        $controller = $this->read('Classes/Controller/Backend/GuardianAjaxController.php');
        self::assertMatchesRegularExpression('/function guardSelfMaintenance\(.*?isSystemMaintainer\(\)/s', $controller);
        self::assertMatchesRegularExpression("/function guardianSelfDisable\(.*?'DISABLE GUARDIAN'/s", $controller);
        self::assertMatchesRegularExpression("/function guardianUninstall\(.*?'REMOVE GUARDIAN'/s", $controller);
        // The removal worker path is bound to the exact Guardian identity.
        self::assertStringContainsString('assertGuardianIdentity(SelfMaintenanceService::GUARDIAN_PACKAGE)', $controller);
    }

    #[Test]
    public function guardianRowOffersDeferredDisableAndUninstallInTheUi(): void
    {
        $js = $this->read('Resources/Public/JavaScript/guardian.js');
        self::assertStringContainsString("data-action=\"guardian-self-disable\"", $js);
        self::assertStringContainsString("data-action=\"guardian-uninstall\"", $js);
        self::assertStringContainsString("!== 'DISABLE GUARDIAN'", $js);
        self::assertStringContainsString("!== 'REMOVE GUARDIAN'", $js);
    }

    #[Test]
    public function guardianButtonsUseSharedDisableRemoveLabelsAndRedStylingByIdentity(): void
    {
        $js = $this->read('Resources/Public/JavaScript/guardian.js');
        // The Guardian block keys destructive styling on identity (is_guardian)
        // and uses the SAME labels as every other extension.
        self::assertMatchesRegularExpression("/p\\.is_guardian && MANAGE\\.mutationsAllowed.*?updater-btn-danger.*?mtxt\\('action\\.disable'/s", $js);
        self::assertMatchesRegularExpression("/p\\.is_guardian && MANAGE\\.mutationsAllowed.*?updater-btn-danger.*?mtxt\\('action\\.remove'/s", $js);
        // The obsolete visible labels are gone.
        self::assertStringNotContainsString("xtxt('guardianDisable'", $js);
        self::assertStringNotContainsString("xtxt('guardianUninstall'", $js);
        self::assertStringNotContainsString('Disable Guardian', $this->read('Resources/Private/Language/locallang.xlf'));
        self::assertStringNotContainsString('Uninstall Guardian', $this->read('Resources/Private/Language/locallang.xlf'));
    }

    #[Test]
    public function ordinaryExtensionButtonsUseNormalStyling(): void
    {
        $js = $this->read('Resources/Public/JavaScript/guardian.js');
        // The generic action button (used for Brickie etc.) has no danger class.
        self::assertMatchesRegularExpression('/data-action="manage-\' \+ kind \+ \'" data-package[^>]*>/', $js);
        self::assertStringNotContainsString('updater-btn-danger" data-action="manage-', $js);
    }

    #[Test]
    public function terDryRunUsesAnIsolatedWorkspaceAndNeverTouchesTheLiveManifest(): void
    {
        $runner = $this->read('Classes/Application/Update/UpdateJobRunner.php');
        // require/remove analyses run in an isolated workspace…
        self::assertMatchesRegularExpression("/in_array\\(\\\$action, \\['require', 'remove'\\], true\\).*?analysisWorkspace->create/s", $runner);
        self::assertStringContainsString('factoryFor($analysisDir)', $runner);
        // …plus a live-file restore safety net for requirement #8.
        self::assertStringContainsString('restoreLiveComposerIfChanged', $runner);
        self::assertStringContainsString('snapshotLiveComposer', $runner);

        $workspace = $this->read('Classes/Infrastructure/Update/AnalysisWorkspace.php');
        self::assertStringContainsString('runtime/analysis', $workspace);
        self::assertStringContainsString('absolutiseRepositories', $workspace);
    }

    #[Test]
    public function jobResultSerializesTheStructuredFailureContract(): void
    {
        $service = $this->read('Classes/Application/Update/UpdateService.php');
        foreach (["'errorCode'", "'details'", "'recommendations'", "'composerExitCode'", "'logAvailable'", "'result_status'"] as $field) {
            self::assertStringContainsString($field . ' =>', $service);
        }

        $js = $this->read('Resources/Public/JavaScript/guardian.js');
        // The poller fetches the archived job (which holds the final detail) and
        // renders summary + details + recommendations + exit code — never a bare
        // "Error" for a real failure.
        self::assertStringContainsString('function gjobFinalize', $js);
        self::assertStringContainsString("endpoint('updateJobDetails')", $js);
        self::assertStringContainsString('function jobFailureHtml', $js);
        self::assertStringContainsString("xtxt('fail.summary.' + code", $js);
        self::assertStringContainsString("xtxt('fail.rec.' + r", $js);
    }

    #[Test]
    public function actionStatusRendersBelowEachTerResultAndInstalledRow(): void
    {
        $js = $this->read('Resources/Public/JavaScript/guardian.js');
        // Per-TER-card status container.
        self::assertStringContainsString("id=\"' + terStatusPrefix(e.extension_key) + '\"", $js);
        // Per-installed-row status container (a dedicated status row + div).
        self::assertStringContainsString("id=\"' + pkgStatusPrefix(p.name) + '-row\"", $js);
        self::assertStringContainsString("<div id=\"' + pkgStatusPrefix(p.name) + '\">", $js);
        // Stale-response guard token.
        self::assertStringContainsString('var OPSEQ', $js);
        self::assertStringContainsString('if (op !== OPSEQ) { return; }', $js);
    }

    #[Test]
    public function languageOverlaysKeepExactKeyParity(): void
    {
        $en = $this->transUnitIds($this->read('Resources/Private/Language/locallang.xlf'));
        $de = $this->transUnitIds($this->read('Resources/Private/Language/de.locallang.xlf'));

        sort($en);
        sort($de);
        self::assertSame($en, $de, 'English and German overlays must declare exactly the same trans-unit ids');
        self::assertContains('tab.extensions', $en);
        self::assertContains('extensions.manage.title', $en);
        self::assertContains('js.ext.install', $en);
        self::assertContains('js.pkg.reason.fingerprint_mismatch', $en);
    }

    /**
     * @return list<string>
     */
    private function transUnitIds(string $xml): array
    {
        preg_match_all('/trans-unit id="([^"]+)"/', $xml, $m);

        return $m[1];
    }
}
