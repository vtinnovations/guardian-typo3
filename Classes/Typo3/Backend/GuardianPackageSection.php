<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Typo3\Backend;

use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use Vtinnovations\GuardianTypo3\Application\Contract\PackageSectionProviderInterface;
use Vtinnovations\GuardianTypo3\Application\Environment\EntitlementReader;
use Vtinnovations\GuardianTypo3\Domain\Configuration\ServiceRecord;

/**
 * Guardian's own section of the shared licence overview.
 *
 * The four endpoints it hands over — read the current state, activate an entered
 * key, refresh the record already held, remove it — are this product's only ones
 * of that kind. They were previously reached from a panel on the product's own
 * settings tab; that panel is gone, and this section is now the single place they
 * are called from, so there is one activation path to reason about, not two.
 *
 * The state handed over is the same administrator-facing projection used
 * everywhere else — masked key, dates, tier, authorised domains, and the code
 * naming any stage that refused. The activation key itself is not part of it.
 */
final class GuardianPackageSection implements PackageSectionProviderInterface
{
    /** Action name => backend AJAX route identifier. */
    private const ACTIONS = [
        'status' => 'guardian_license_status',
        'activate' => 'guardian_license_activate',
        'refresh' => 'guardian_license_refresh',
        'clear' => 'guardian_license_clear',
    ];

    public function __construct(
        private readonly EntitlementReader $entitlement,
        private readonly UriBuilder $uriBuilder,
    ) {
    }

    public function title(): string
    {
        return ServiceRecord::PROJECT;
    }

    public function slug(): string
    {
        return ServiceRecord::PROJECT_SLUG;
    }

    public function state(): array
    {
        return $this->entitlement->grant()->toPublicArray();
    }

    public function actions(): array
    {
        $actions = [];
        foreach (self::ACTIONS as $name => $identifier) {
            try {
                // Backend AJAX routes are registered under an "ajax_" prefix; the
                // un-prefixed name does not resolve.
                $actions[$name] = (string) $this->uriBuilder->buildUriFromRoute('ajax_' . $identifier);
            } catch (RouteNotFoundException) {
                // A section with no endpoint renders read-only rather than
                // offering a control that would post nowhere.
            }
        }

        return $actions;
    }
}
