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
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use Vtinnovations\GuardianTypo3\Application\Contract\BackendAuthorizationInterface;
use Vtinnovations\GuardianTypo3\Application\Environment\EntitlementReader;
use Vtinnovations\GuardianTypo3\Application\Environment\PackageDirectory;
use Vtinnovations\GuardianTypo3\Domain\Configuration\ServiceRecord;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\EntryNotice;

/**
 * The shared V-T.ONE licence screen.
 *
 * It is a host, not a product page: one section per installed V-T.ONE product,
 * each headed with that product's own name, each posting to that product's own
 * endpoints. Guardian happens to supply the first section, and would render
 * exactly the same way if another extension supplied a second.
 *
 * The module is registered under one identifier that every V-T.ONE extension uses,
 * so installing several of them produces one entry in the System menu rather than
 * a competing one per product. A product contributes through the registry
 * described in {@see PackageDirectory}.
 *
 * Opening this screen is an entry into the protected module, so the once-per-
 * session notice is armed here for each section actually rendered — once per
 * session and product, however many times the screen is reloaded.
 */
final class PackageOverviewController
{
    /** What each section is headed with, after the product's own name. */
    private const SECTION_SUFFIX = ' Licence management';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendAuthorizationInterface $authorization,
        private readonly PackageDirectory $directory,
        private readonly EntitlementReader $entitlement,
        private readonly EntryNotice $notice,
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        // The module is registered admin-only; asserting it here as well keeps the
        // guarantee out of the routing configuration alone.
        $this->authorization->assertAdministrator();

        // The registry decides which sections exist and which of them may offer
        // controls; a product that cannot carry out all four operations renders a
        // sentence instead of a button that would post nowhere.
        $sections = $this->directory->sections(self::SECTION_SUFFIX);

        // This product's own section has been rendered, so this is its module
        // entry. The claim inside makes repeats within the session silent.
        if ($this->hasSection($sections, ServiceRecord::PROJECT_SLUG)) {
            $this->notice->arm($this->entitlement->grant(), ServiceRecord::PROJECT_SLUG);
        }

        $this->pageRenderer->addCssFile('EXT:guardian_typo3/Resources/Public/Css/guardian.css');
        $this->pageRenderer->addJsFile('EXT:guardian_typo3/Resources/Public/JavaScript/vtone-packages.js');
        // The screen's transient messages ("verifying…", "the licence was not
        // accepted") are written by its script, so it needs the same labels the
        // sections were rendered with.
        $this->pageRenderer->addInlineLanguageLabelFile(
            'EXT:guardian_typo3/Resources/Private/Language/locallang.xlf'
        );

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('VTOne Licensing');
        $view->assignMultiple([
            'headline' => 'VTOne Licensing',
            'sections' => $sections,
            'configJson' => $this->configJson($sections),
        ]);

        return $view->renderResponse('Packages/Index');
    }

    /**
     * @param list<array{slug: string, title: string, complete: bool, state: array<string, mixed>, actions: array<string, string>}> $sections
     */
    private function hasSection(array $sections, string $slug): bool
    {
        foreach ($sections as $section) {
            if ($section['slug'] === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * The island the screen's script reads: which sections exist and which local
     * backend routes their controls post to. State is deliberately not repeated
     * here — the server renders it, and the script reloads the page after an
     * accepted operation rather than keeping a second copy of it.
     *
     * Only sections that rendered controls appear. A section the screen showed as
     * unavailable has nothing to post to, and publishing an endpoint for it would
     * contradict what the administrator was just told.
     *
     * @param list<array{slug: string, title: string, complete: bool, state: array<string, mixed>, actions: array<string, string>}> $sections
     */
    private function configJson(array $sections): string
    {
        $wiring = [];
        foreach ($sections as $section) {
            if ($section['complete'] === true) {
                $wiring[] = ['slug' => $section['slug'], 'actions' => $section['actions']];
            }
        }

        $json = json_encode(
            ['sections' => $wiring],
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
        );

        return $json === false ? '{"sections":[]}' : $json;
    }
}
