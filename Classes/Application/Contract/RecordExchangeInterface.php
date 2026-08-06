<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Contract;

use Vtinnovations\GuardianTypo3\Domain\Configuration\ProvisioningOutcome;

/**
 * Outbound port for the two administrator-initiated exchanges.
 *
 * Both return a complete package or nothing usable; neither ever returns a
 * partial change. An implementation must treat a transport failure as
 * "unreachable" rather than as a refusal, because a network problem is not the
 * vendor withdrawing an entitlement.
 */
interface RecordExchangeInterface
{
    /** First activation of a key entered by an administrator. */
    public function activate(string $key, string $host, int $now): ProvisioningOutcome;

    /** Administrator-triggered refresh of the record already held. */
    public function refresh(string $key, string $host, int $currentVersion, int $now): ProvisioningOutcome;
}
