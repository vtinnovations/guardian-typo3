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

use Vtinnovations\GuardianTypo3\Domain\License\LicenseVerificationResult;

/**
 * Port for the authoritative "Update License" refresh: it fetches the current,
 * complete license document (dates, lifetime flag, entitlements, document
 * version and detached signature) from the V-T.ONE license-updater endpoint. An
 * unreachable server yields an "unreachable" result so a working stored license
 * is never revoked by a transient network failure.
 */
interface LicenseUpdaterInterface
{
    public function update(string $licenseKey, string $domain, string $requiredPackage = ''): LicenseVerificationResult;
}
