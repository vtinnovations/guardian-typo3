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

use Vtinnovations\GuardianTypo3\Domain\Environment\HostInventory;

/**
 * Answers "which hosts is this installation configured to serve?".
 *
 * This is a different question from {@see InstallationIdentityInterface}, which
 * answers which host is being served right now. The two are used together: the
 * current host says where the request came from, the inventory says what the
 * operator actually configured, and only the second is beyond a caller's reach.
 *
 * The answer must come from the framework's own configuration. A request header,
 * a query parameter, a cookie or a value echoed back by a remote service is not an
 * acceptable source, because the point of the inventory is to be the half of the
 * decision that cannot be supplied by whoever is asking.
 */
interface ConfiguredHostsInterface
{
    /**
     * Every host the installation is configured to serve, in configuration order.
     * An installation with no site configuration has an empty inventory, which is
     * a refusal to activate and never a permission.
     */
    public function inventory(): HostInventory;
}
