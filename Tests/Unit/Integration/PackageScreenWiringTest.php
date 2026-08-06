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
use Psr\Container\ContainerInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\PackageSectionProviderInterface;
use Vtinnovations\GuardianTypo3\Application\Environment\PackageDirectory;

/**
 * The shared licence screen: where it appears, what it is called, and how two
 * V-T.ONE products share it without standing on each other.
 *
 * The screen's identifier is deliberately the same string in every V-T.ONE
 * extension. TYPO3 merges module registrations by identifier, so installing two
 * of them produces one entry that lists both rather than two competing entries —
 * which is only true as long as the identifier and its shape stay exactly as they
 * are here.
 */
final class PackageScreenWiringTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $previousRegistry = null;

    protected function setUp(): void
    {
        $this->previousRegistry = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['vtone'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousRegistry === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['vtone']);

            return;
        }
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['vtone'] = $this->previousRegistry;
    }

    private function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function modules(): array
    {
        return require $this->root() . '/Configuration/Backend/Modules.php';
    }

    #[Test]
    public function theSharedScreenIsRegisteredUnderOneNeutralIdentifier(): void
    {
        $modules = $this->modules();

        self::assertArrayHasKey('vtone_licensing', $modules);
        // Not "guardian_licensing": a per-product identifier would give every
        // installed V-T.ONE extension its own competing entry.
        foreach (array_keys($modules) as $identifier) {
            self::assertNotSame('guardian_vtone_licensing', $identifier);
        }

        $module = $modules['vtone_licensing'];
        self::assertSame('system', $module['parent']);
        self::assertSame('admin', $module['access'], 'the screen is for administrators only');
        self::assertStringContainsString('PackageOverviewController', $module['routes']['_default']['target']);
    }

    #[Test]
    public function theScreenIsTitledExactlyVTOneLicensing(): void
    {
        $labels = (string) file_get_contents(
            $this->root() . '/Resources/Private/Language/locallang_vtone.xlf'
        );

        self::assertStringContainsString('<source>VTOne Licensing</source>', $labels);

        $controller = (string) file_get_contents(
            $this->root() . '/Classes/Controller/Backend/PackageOverviewController.php'
        );
        self::assertStringContainsString("setTitle('VTOne Licensing')", $controller);
    }

    #[Test]
    public function eachSectionIsHeadedWithItsOwnProductNameAndLicenceManagement(): void
    {
        $controller = (string) file_get_contents(
            $this->root() . '/Classes/Controller/Backend/PackageOverviewController.php'
        );

        self::assertStringContainsString("' Licence management'", $controller);
        // The product's own name, not a fixed one: a second product's section is
        // headed with its own.
        self::assertStringContainsString('$title . self::SECTION_SUFFIX', $controller);
    }

    #[Test]
    public function thisProductRegistersItselfWithItsOwnSlug(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['vtone']);
        // The file refuses to run outside TYPO3, which is what keeps it from being
        // requested directly over the web; the guard is satisfied rather than
        // removed so the shipped file is the one under test.
        if (!\defined('TYPO3')) {
            \define('TYPO3', true);
        }
        require $this->root() . '/ext_localconf.php';

        $registry = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['vtone']['packages'];

        self::assertArrayHasKey('guardian', $registry);
        self::assertSame('Guardian', $registry['guardian']['title']);
        self::assertStringContainsString('GuardianPackageSection', $registry['guardian']['provider']);
    }

    #[Test]
    public function twoProductsCoexistWithoutOverwritingEachOther(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['vtone']['packages'] = [
            'guardian' => ['title' => 'Guardian', 'provider' => 'section.guardian'],
            'brickie' => ['title' => 'Brickie', 'provider' => 'section.brickie'],
        ];

        $providers = (new PackageDirectory($this->container([
            'section.guardian' => $this->section('Guardian', 'guardian'),
            'section.brickie' => $this->section('Brickie', 'brickie'),
        ])))->providers();

        // Both are present, in a stable order, each with its own state.
        self::assertSame(['brickie', 'guardian'], array_keys($providers));
        self::assertSame('Brickie', $providers['brickie']->title());
        self::assertSame('Guardian', $providers['guardian']->title());
        self::assertSame(['slug' => 'brickie'], $providers['brickie']->state());
    }

    #[Test]
    public function aProductThatCannotBeResolvedIsSkippedRatherThanBreakingTheScreen(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['vtone']['packages'] = [
            'guardian' => ['title' => 'Guardian', 'provider' => 'section.guardian'],
            'gone' => ['title' => 'Gone', 'provider' => 'section.missing'],
            'malformed' => ['title' => 'Malformed'],
            'wrong' => ['title' => 'Wrong', 'provider' => 'section.wrong'],
        ];

        $providers = (new PackageDirectory($this->container([
            'section.guardian' => $this->section('Guardian', 'guardian'),
            'section.wrong' => new \stdClass(),
        ])))->providers();

        self::assertSame(['guardian'], array_keys($providers));
    }

    #[Test]
    public function anEmptyRegistryIsAnEmptyScreenRatherThanAnError(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['vtone']);

        self::assertSame([], (new PackageDirectory($this->container([])))->providers());
    }

    #[Test]
    public function acompleteProductRendersItsControlsUnderItsOwnHeading(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['vtone']['packages'] = [
            'guardian' => ['title' => 'Guardian', 'provider' => 'section.guardian'],
        ];

        $sections = (new PackageDirectory($this->container([
            'section.guardian' => $this->section('Guardian', 'guardian'),
        ])))->sections(' Licence management');

        self::assertCount(1, $sections);
        self::assertTrue($sections[0]['complete']);
        self::assertSame('Guardian Licence management', $sections[0]['title']);
        self::assertSame(['slug' => 'guardian'], $sections[0]['state']);
        self::assertArrayHasKey('clear', $sections[0]['actions']);
    }

    /**
     * A product that can be read but not acted on is the case that matters: three
     * working buttons and a fourth that posts nowhere would let an administrator
     * believe a licence had been removed when it had not.
     */
    #[Test]
    public function aProductMissingAnyOneOperationOffersNoControlsAtAll(): void
    {
        foreach (['status', 'activate', 'refresh', 'clear'] as $missing) {
            $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['vtone']['packages'] = [
                'guardian' => ['title' => 'Guardian', 'provider' => 'section.guardian'],
            ];

            $sections = (new PackageDirectory($this->container([
                'section.guardian' => $this->section('Guardian', 'guardian', $missing),
            ])))->sections(' Licence management');

            self::assertCount(1, $sections, 'the section is still shown when ' . $missing . ' is missing');
            self::assertFalse($sections[0]['complete'], 'a missing ' . $missing . ' must withdraw every control');
            self::assertSame([], $sections[0]['actions'], 'no endpoint is published for ' . $missing);
            self::assertSame([], $sections[0]['state']);
            // Named, so the administrator can tell which product is affected.
            self::assertSame('Guardian Licence management', $sections[0]['title']);
        }
    }

    #[Test]
    public function anEmptyActionUrlCountsAsMissingRatherThanPresent(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['vtone']['packages'] = [
            'guardian' => ['title' => 'Guardian', 'provider' => 'section.guardian'],
        ];

        $sections = (new PackageDirectory($this->container([
            'section.guardian' => $this->section('Guardian', 'guardian', null, ['clear' => '']),
        ])))->sections(' Licence management');

        self::assertFalse($sections[0]['complete']);
    }

    #[Test]
    public function aProviderThatThrowsFailsVisiblyWithoutTakingTheScreenDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['vtone']['packages'] = [
            'broken' => ['title' => 'Broken', 'provider' => 'section.broken'],
            'guardian' => ['title' => 'Guardian', 'provider' => 'section.guardian'],
        ];

        $sections = (new PackageDirectory($this->container([
            'section.broken' => $this->throwingSection('Broken', 'broken'),
            'section.guardian' => $this->section('Guardian', 'guardian'),
        ])))->sections(' Licence management');

        self::assertCount(2, $sections);
        self::assertSame('broken', $sections[0]['slug']);
        self::assertFalse($sections[0]['complete'], 'a throwing product must not appear to work');
        self::assertSame([], $sections[0]['actions']);
        // The other product is entirely unaffected — that is why one failing
        // section is caught rather than allowed to abort the screen.
        self::assertTrue($sections[1]['complete']);
    }

    #[Test]
    public function theScreenPublishesEndpointsOnlyForSectionsItRenderedControlsFor(): void
    {
        $controller = (string) file_get_contents(
            $this->root() . '/Classes/Controller/Backend/PackageOverviewController.php'
        );

        // The island the browser reads must not contradict what was rendered.
        self::assertMatchesRegularExpression(
            "/if \(\\\$section\['complete'\] === true\)/",
            $controller,
            'an unavailable section must not publish endpoints'
        );

        $template = (string) file_get_contents(
            $this->root() . '/Resources/Private/Templates/Packages/Index.html'
        );
        self::assertStringContainsString('condition="{section.complete}"', $template);
        self::assertStringContainsString('packages.unavailable', $template);
    }

    /**
     * @param array<string, object> $services
     */
    private function container(array $services): ContainerInterface
    {
        return new class ($services) implements ContainerInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services)
            {
            }

            public function get(string $id): object
            {
                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };
    }

    /**
     * A product offering the complete contract, optionally with one operation
     * withheld or overridden so the fail-closed rule can be exercised.
     *
     * @param array<string, string> $overrides
     */
    private function section(
        string $title,
        string $slug,
        ?string $withhold = null,
        array $overrides = [],
    ): PackageSectionProviderInterface {
        return new class ($title, $slug, $withhold, $overrides) implements PackageSectionProviderInterface {
            /** @param array<string, string> $overrides */
            public function __construct(
                private readonly string $title,
                private readonly string $slug,
                private readonly ?string $withhold,
                private readonly array $overrides,
            ) {
            }

            public function title(): string
            {
                return $this->title;
            }

            public function slug(): string
            {
                return $this->slug;
            }

            public function state(): array
            {
                return ['slug' => $this->slug];
            }

            public function actions(): array
            {
                $actions = [];
                foreach (['status', 'activate', 'refresh', 'clear'] as $name) {
                    if ($name !== $this->withhold) {
                        $actions[$name] = '/typo3/ajax/' . $this->slug . '/' . $name;
                    }
                }

                return array_merge($actions, $this->overrides);
            }
        };
    }

    private function throwingSection(string $title, string $slug): PackageSectionProviderInterface
    {
        return new class ($title, $slug) implements PackageSectionProviderInterface {
            public function __construct(private readonly string $title, private readonly string $slug)
            {
            }

            public function title(): string
            {
                return $this->title;
            }

            public function slug(): string
            {
                return $this->slug;
            }

            public function state(): array
            {
                throw new \RuntimeException('the product is broken');
            }

            public function actions(): array
            {
                return [];
            }
        };
    }
}
