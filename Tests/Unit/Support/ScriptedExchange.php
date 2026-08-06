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

use Vtinnovations\GuardianTypo3\Application\Contract\RecordExchangeInterface;
use Vtinnovations\GuardianTypo3\Domain\Configuration\ProvisioningOutcome;

final class ScriptedExchange implements RecordExchangeInterface
{
    /** @var list<array{operation: string, key: string, host: string, version: int}> */
    public array $calls = [];

    /** @var list<ProvisioningOutcome> */
    private array $answers;

    public function __construct(ProvisioningOutcome ...$answers)
    {
        $this->answers = $answers;
    }

    public function activate(string $key, string $host, int $now): ProvisioningOutcome
    {
        $this->calls[] = ['operation' => 'activate', 'key' => $key, 'host' => $host, 'version' => 0];

        return $this->next();
    }

    public function refresh(string $key, string $host, int $currentVersion, int $now): ProvisioningOutcome
    {
        $this->calls[] = ['operation' => 'refresh', 'key' => $key, 'host' => $host, 'version' => $currentVersion];

        return $this->next();
    }

    private function next(): ProvisioningOutcome
    {
        return array_shift($this->answers)
            ?? ProvisioningOutcome::unreachable('transport_failed', 'No scripted answer.');
    }
}
