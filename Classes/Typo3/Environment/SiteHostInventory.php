<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Typo3\Environment;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use Vtinnovations\GuardianTypo3\Application\Contract\ConfiguredHostsInterface;
use Vtinnovations\GuardianTypo3\Domain\Environment\HostInventory;

/**
 * TYPO3 adapter that reads the configured hosts out of Site Configuration.
 *
 * Everything here comes from `config/sites/*\/config.yaml` by way of TYPO3's own
 * site objects — files an operator writes, not anything a visitor can influence.
 * Three places in a site definition can name a host, and all three are configured
 * rather than requested, so all three count:
 *
 *   - the site's own `base`;
 *   - each language's `base`, which may be a different host entirely on installs
 *     that give a language its own domain;
 *   - each entry under `baseVariants`, which is how staging and production hosts
 *     for the same site are declared.
 *
 * Sites are read in identifier order and each site's own base comes first, so the
 * inventory is the same on every node and on every request. The first entry is
 * therefore a stable primary name rather than whichever site happened to be
 * resolved.
 *
 * This is the whole-instance inventory, matching a package whose entitlement is
 * one state for the installation. A relative base such as `/` names no host and
 * simply contributes nothing.
 */
final class SiteHostInventory implements ConfiguredHostsInterface
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {
    }

    public function inventory(): HostInventory
    {
        try {
            $sites = $this->siteFinder->getAllSites();
        } catch (\Throwable) {
            // A broken or absent site configuration yields no inventory, which
            // withholds entitlement. It must never yield a permissive one.
            return HostInventory::empty();
        }

        ksort($sites, \SORT_STRING);

        $candidates = [];
        foreach ($sites as $site) {
            if ($site instanceof Site) {
                foreach ($this->hostsOf($site) as $host) {
                    $candidates[] = $host;
                }
            }
        }

        return HostInventory::of($candidates);
    }

    /**
     * @return list<string>
     */
    private function hostsOf(Site $site): array
    {
        $hosts = [$site->getBase()->getHost()];

        foreach ($site->getAllLanguages() as $language) {
            $hosts[] = $language->getBase()->getHost();
        }

        // Base variants are plain configuration entries rather than objects, so
        // they are read as written and normalised like any other candidate.
        $variants = $site->getConfiguration()['baseVariants'] ?? null;
        if (\is_array($variants)) {
            foreach ($variants as $variant) {
                if (\is_array($variant) && \is_string($variant['base'] ?? null)) {
                    $hosts[] = $variant['base'];
                }
            }
        }

        return array_values(array_filter($hosts, static fn (string $host): bool => $host !== ''));
    }
}
