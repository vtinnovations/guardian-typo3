<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Environment;

use Vtinnovations\GuardianTypo3\Domain\Environment\CapabilityTier;
use Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException;

/**
 * The check a privileged operation performs immediately before doing its work.
 *
 * It is deliberately used at each protected boundary rather than once at the
 * edge, so that a caller which reaches a service directly — a console command, a
 * scheduler task, a queue worker, another service — is held to the same
 * requirement as one arriving through the backend. Removing any single call site
 * therefore affects only that one operation.
 *
 * The message an administrator sees is generic. It says which plan the operation
 * needs and nothing about why the current record did not qualify.
 */
final class CapabilityAssertion
{
    public function __construct(private readonly EntitlementReader $entitlement)
    {
    }

    /**
     * The lower of the two requirements: any activated record still in effect,
     * or an expired paid one carrying the vendor's signed Free fallback. Manual
     * backup is the operation this exists for.
     *
     * @throws GuardianException when the installation is not entitled
     */
    public function requireLicensed(string $operation): void
    {
        $this->require(CapabilityTier::Free, $operation);
    }

    /** @throws GuardianException when the installation is not entitled to Pro */
    public function requirePro(string $operation): void
    {
        $this->require(CapabilityTier::Pro, $operation);
    }

    /** @throws GuardianException when the installation is not entitled */
    public function require(CapabilityTier $tier, string $operation): void
    {
        if ($this->entitlement->allows($tier)) {
            return;
        }

        throw new GuardianException(sprintf(
            '%s requires a valid %s licence from v-t.one.',
            $operation,
            $tier === CapabilityTier::Pro ? 'Pro' : 'Free or Pro'
        ));
    }
}
