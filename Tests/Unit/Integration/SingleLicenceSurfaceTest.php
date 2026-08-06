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
 * There is one place a licence is managed from, and it is the shared V-T.ONE
 * screen.
 *
 * The product used to carry its own panel on the Settings tab: an input, an
 * activate button, a remove button and a status area, with its own script and its
 * own copy of the state. That panel is gone. These tests are what keeps it gone —
 * not by looking for the panel, which would pass the moment someone renames it,
 * but by asserting that the elements it was built from exist nowhere outside the
 * shared screen, and that every route it used has exactly one caller.
 *
 * They read the shipped files rather than a container: what matters here is what
 * an installation actually receives.
 */
final class SingleLicenceSurfaceTest extends TestCase
{
    /** The four endpoints that make up the whole administrator-facing flow. */
    private const ROUTES = [
        'guardian_license_status',
        'guardian_license_activate',
        'guardian_license_refresh',
        'guardian_license_clear',
    ];

    private function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents($this->root() . '/' . $relative);
    }

    /** @return list<string> */
    private function shippedFiles(): array
    {
        $found = [];
        foreach (['Classes', 'Configuration', 'Resources'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root() . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $found[] = (string) $file->getPathname();
                }
            }
        }

        return $found;
    }

    #[Test]
    public function theSettingsTabNoLongerCarriesALicencePanel(): void
    {
        $settings = $this->read('Resources/Private/Partials/Guardian/Settings.html');

        // No input, no buttons, no status area — the controls are not hidden or
        // disabled here, they are absent.
        self::assertStringNotContainsString('proLicense', $settings);
        self::assertStringNotContainsString('settings.license.placeholder', $settings);
        self::assertStringNotContainsString('settings.license.activate', $settings);
        self::assertStringNotContainsString('settings.license.remove', $settings);

        // What remains is a pointer to the one screen that does carry them.
        self::assertStringContainsString('settings.license.moved', $settings);
        self::assertStringContainsString('{licensingUrl}', $settings);
    }

    #[Test]
    public function theProductModuleScriptContainsNoLicenceFlowAtAll(): void
    {
        $js = $this->read('Resources/Public/JavaScript/guardian.js');

        foreach ([
            'proLicense',
            'licenseFetch',
            'licenseActivate',
            'licenseClear',
            'licenseStatus',
            'renderLicense',
            'loadLicenseStatus',
            'registerLicenseControls',
        ] as $symbol) {
            self::assertStringNotContainsString($symbol, $js, $symbol . ' is a removed licence-panel symbol');
        }
    }

    #[Test]
    public function theProductModuleIsNotHandedTheLicenceEndpoints(): void
    {
        $controller = $this->read('Classes/Controller/Backend/GuardianModuleController.php');

        foreach (self::ROUTES as $route) {
            self::assertStringNotContainsString($route, $controller, $route . ' must not reach the product module');
        }
        // It links to the shared screen instead of hosting the controls.
        self::assertStringContainsString("buildUriFromRoute('vtone_licensing')", $controller);
        self::assertStringContainsString("'licensingUrl' => \$this->licensingUrl()", $controller);
    }

    #[Test]
    public function everyLicenceRouteIsRegisteredOnceAndCalledOnlyFromTheSharedSection(): void
    {
        $routes = $this->read('Configuration/Backend/AjaxRoutes.php');
        $section = $this->read('Classes/Typo3/Backend/GuardianPackageSection.php');

        foreach (self::ROUTES as $route) {
            self::assertSame(1, substr_count($routes, "'" . $route . "' => ["), $route . ' is registered once');
            self::assertStringContainsString("'" . $route . "'", $section, $route . ' is offered by the section');
        }

        // Nothing else in the shipped tree names them.
        foreach ($this->shippedFiles() as $file) {
            if (str_ends_with($file, 'AjaxRoutes.php') || str_ends_with($file, 'GuardianPackageSection.php')) {
                continue;
            }
            $contents = (string) file_get_contents($file);
            foreach (self::ROUTES as $route) {
                self::assertStringNotContainsString($route, $contents, basename($file) . ' must not reference ' . $route);
            }
        }
    }

    #[Test]
    public function theSectionOffersReadActivateRefreshAndRemove(): void
    {
        $section = $this->read('Classes/Typo3/Backend/GuardianPackageSection.php');

        foreach (['status', 'activate', 'refresh', 'clear'] as $action) {
            self::assertMatchesRegularExpression(
                "/'" . $action . "' => 'guardian_license_/",
                $section,
                $action . ' must be part of the section contract'
            );
        }
    }

    #[Test]
    public function allFourEndpointsAreAdministratorOnlyAndTheWritingOnesArePost(): void
    {
        $controller = $this->read('Classes/Controller/Backend/GuardianAjaxController.php');

        // Reading state is admin-gated…
        self::assertMatchesRegularExpression('/function licenseStatus\(.*?\$this->guard\(\)/s', $controller);
        // …and everything that changes state is admin-gated AND POST-only, which
        // is what carries TYPO3's route token.
        foreach (['licenseActivate', 'licenseRefresh', 'licenseClear'] as $method) {
            self::assertMatchesRegularExpression(
                '/function ' . $method . '\(.*?guardPost\(\$request\)/s',
                $controller,
                $method . ' must be POST + admin'
            );
        }
    }

    #[Test]
    public function updateLicenceGoesThroughTheRefreshFlowRatherThanReActivating(): void
    {
        $controller = $this->read('Classes/Controller/Backend/GuardianAjaxController.php');
        self::assertMatchesRegularExpression(
            '/function licenseRefresh\(.*?activation->refresh\(\)/s',
            $controller
        );

        // The refresh flow is the one that announces the version already held.
        $activation = $this->read('Classes/Application/Configuration/ActivationService.php');
        self::assertMatchesRegularExpression(
            '/private function applyRefresh\(.*?exchange->refresh\(\$key, \$host, \$current->version/s',
            $activation
        );

        // No key is submitted with an update: the request body starts empty and
        // only the activate branch ever puts one in it, so the stored key is used
        // server-side and never makes the round trip through the browser.
        $js = $this->read('Resources/Public/JavaScript/vtone-packages.js');
        self::assertStringContainsString('var body = {};', $js);
        self::assertSame(1, substr_count($js, 'body = { key: key };'));
        self::assertMatchesRegularExpression(
            "/if \(role === 'activate'\).*body = \{ key: key \};/s",
            $js,
            'only activation may carry a key'
        );
    }

    #[Test]
    public function theSharedScreenRendersItsStateOnTheServerAndNeverWaitsForIt(): void
    {
        $template = $this->read('Resources/Private/Templates/Packages/Index.html');

        self::assertStringContainsString('partial="Packages/State"', $template);
        foreach (['data-role="activate"', 'data-role="refresh"', 'data-role="clear"', 'data-role="key"'] as $control) {
            self::assertStringContainsString($control, $template);
        }

        // The forbidden state: a placeholder that can stay on screen forever.
        foreach ($this->shippedFiles() as $file) {
            $contents = (string) file_get_contents($file);
            self::assertStringNotContainsStringIgnoringCase(
                'Loading current license',
                $contents,
                basename($file) . ' must not ship an indefinite loading placeholder'
            );
        }
    }

    #[Test]
    public function theScreenTalksToTheLocalBackendOnlyAndKeepsNoCopyOfTheState(): void
    {
        $js = $this->read('Resources/Public/JavaScript/vtone-packages.js');

        // Every request goes to a route the server built; the vendor is never
        // addressed from the browser.
        self::assertStringNotContainsString('v-t.one', $js);
        self::assertStringNotContainsString('https://', $js);
        self::assertMatchesRegularExpression('/section\.actions/', $js);

        // After an accepted operation the server re-renders; the script does not
        // keep a second opinion of what is stored.
        self::assertStringContainsString('window.location.reload()', $js);

        // The island carries wiring, not state.
        $controller = $this->read('Classes/Controller/Backend/PackageOverviewController.php');
        self::assertMatchesRegularExpression(
            "/\\\$wiring\[\] = \['slug' => \\\$section\['slug'\], 'actions' => \\\$section\['actions'\]\]/",
            $controller
        );
    }

    #[Test]
    public function theSessionNoticeIsArmedOnlyWhereAModuleIsActuallyEntered(): void
    {
        $armers = [];
        foreach ($this->shippedFiles() as $file) {
            if (str_contains((string) file_get_contents($file), '->notice->arm(')) {
                $armers[] = basename($file);
            }
        }
        sort($armers);

        // The product's own module and the shared screen — both are real module
        // entries, and the claim inside makes the pair produce one event per
        // session. No removed panel, listener or service can add a third.
        self::assertSame(
            ['GuardianModuleController.php', 'PackageOverviewController.php'],
            $armers
        );
    }
}
