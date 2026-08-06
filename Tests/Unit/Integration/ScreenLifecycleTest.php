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
 * The licence screen's script must survive the order TYPO3 actually loads it in.
 *
 * `PageRenderer::addJsFile()` places a backend module's script in the document
 * head, so the file runs before the Fluid markup it binds to exists. A file that
 * looks its container up at the top level and returns when it is absent returns
 * permanently — and the screen then renders three buttons that produce no
 * request, no error and no message. Activation, update and removal all look
 * available and none of them work.
 *
 * That failure is invisible to every other kind of check: the routes exist, the
 * template renders, the URLs are generated, the file contains `addEventListener`.
 * Only executing the asset in both orders and watching for the request finds it,
 * which is what `Tests/Browser/vtone-packages.lifecycle.mjs` does.
 *
 * The assertions here are the structural half of that gate. They are cheap, they
 * run in the ordinary suite, and they fail immediately if the shape that makes
 * the screen bindable is edited away.
 */
final class ScreenLifecycleTest extends TestCase
{
    private function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    private function asset(): string
    {
        return (string) file_get_contents(
            $this->root() . '/Resources/Public/JavaScript/vtone-packages.js'
        );
    }

    /**
     * The whole point: nothing may look at the document until the document is
     * ready. Any `getElementById` outside a function body runs while the head is
     * being parsed and can only ever find nothing.
     */
    #[Test]
    public function noDocumentLookupHappensAtTheTopLevelOfTheAsset(): void
    {
        foreach (explode("\n", $this->asset()) as $number => $line) {
            // Top level is column zero inside the IIFE — anything bound to the
            // document there has not waited for it.
            if (preg_match('/^    var .*document\.(getElementById|querySelector)/', $line) === 1) {
                self::fail(sprintf(
                    'line %d looks the document up before it is ready: %s',
                    $number + 1,
                    trim($line)
                ));
            }
        }

        self::assertStringNotContainsString(
            "    if (!root || !island) {\n        return;",
            $this->asset(),
            'a top-level early return leaves every control inert'
        );
    }

    #[Test]
    public function theAssetBindsThroughAReadinessAwareInitializer(): void
    {
        $asset = $this->asset();

        // Both orders are handled: the listener for a document still parsing, and
        // the immediate call for one that has already finished.
        self::assertStringContainsString("document.readyState === 'loading'", $asset);
        self::assertStringContainsString("document.addEventListener('DOMContentLoaded', boot", $asset);
        self::assertMatchesRegularExpression('/\}\s*else\s*\{\s*boot\(\);/', $asset);

        // Every lookup lives inside the initializer.
        self::assertMatchesRegularExpression(
            '/function boot\(\)\s*\{.*document\.getElementById\(ROOT_ID\)/s',
            $asset
        );
    }

    /**
     * A second boot — a remount, a partial navigation, a second copy of the
     * asset — must not install a second handler, or one press would activate,
     * refresh or remove twice.
     */
    #[Test]
    public function bindingIsIdempotentAndClaimedBeforeAnyListenerIsAdded(): void
    {
        $asset = $this->asset();

        self::assertStringContainsString("root.getAttribute(BOUND_ATTRIBUTE) === '1'", $asset);
        self::assertMatchesRegularExpression(
            '/root\.setAttribute\(BOUND_ATTRIBUTE, \'1\'\);.*root\.addEventListener\(\'click\'/s',
            $asset,
            'the screen must be claimed before it is bound, not after'
        );
    }

    /** A control that cannot reach an endpoint is disabled and says so. */
    #[Test]
    public function anUnwirableControlFailsVisiblyRatherThanSilently(): void
    {
        $asset = $this->asset();

        self::assertMatchesRegularExpression(
            '/if \(!url\) \{\s*disable\(card, t\(\'js\.license\.endpointMissing\'/',
            $asset
        );
        self::assertMatchesRegularExpression(
            '/if \(wiring === null\) \{.*disable\(cards\[i\], t\(\'js\.license\.endpointMissing\'/s',
            $asset,
            'a missing endpoint island must disable the controls, not leave them looking live'
        );
    }

    #[Test]
    public function aSecondClickWhileARequestIsInFlightIsIgnored(): void
    {
        self::assertStringContainsString("card.getAttribute('data-vtone-busy') === '1'", $this->asset());
    }

    /** The key is read from the field and never persisted anywhere by the browser. */
    #[Test]
    public function theActivationKeyNeverLeavesTheRequestBody(): void
    {
        $asset = $this->asset();

        foreach (['localStorage', 'sessionStorage', 'document.cookie', 'console.log'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $asset, $forbidden . ' must not appear');
        }
        // A refresh submits no key at all: the stored one is used server-side.
        self::assertStringContainsString('var body = {};', $asset);
        self::assertStringContainsString("body = { key: key };", $asset);
    }

    /**
     * The executable half of the gate must exist and must actually exercise every
     * control in both orders. A structural test alone was what let the original
     * defect ship.
     */
    #[Test]
    public function theExecutableTwoOrderAcceptanceGateIsPresent(): void
    {
        $path = $this->root() . '/Tests/Browser/vtone-packages.lifecycle.mjs';
        self::assertFileExists($path, 'the two-order acceptance harness is a release gate');

        $harness = (string) file_get_contents($path);

        self::assertStringContainsString('Order 1 — asset evaluated before the module markup exists', $harness);
        self::assertStringContainsString('Order 2 — module markup exists before the asset is evaluated', $harness);
        foreach (['activate', 'refresh', 'remove'] as $operation) {
            self::assertStringContainsString(
                $operation . ' creates exactly one local request',
                $harness,
                $operation . ' must be clicked and counted'
            );
        }
        // It runs the shipped file rather than a copy of it.
        self::assertStringContainsString("Resources/Public/JavaScript/vtone-packages.js", $harness);
        self::assertStringContainsString('process.exit(failures === 0 ? 0 : 1)', $harness);
    }
}
