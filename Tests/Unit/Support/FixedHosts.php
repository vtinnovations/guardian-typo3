<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Support;

use Vtinnovations\GuardianTypo3\Application\Contract\ConfiguredHostsInterface;
use Vtinnovations\GuardianTypo3\Domain\Environment\HostInventory;

/**
 * A site configuration, without TYPO3. The list stands in for what an operator
 * put in `config/sites/*\/config.yaml`.
 */
final class FixedHosts implements ConfiguredHostsInterface
{
    /** @var list<string> */
    private array $hosts;

    public function __construct(string ...$hosts)
    {
        $this->hosts = array_values($hosts);
    }

    public function inventory(): HostInventory
    {
        return HostInventory::of($this->hosts);
    }

    public function set(string ...$hosts): void
    {
        $this->hosts = array_values($hosts);
    }
}
