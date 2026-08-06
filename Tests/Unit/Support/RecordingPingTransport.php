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

use Vtinnovations\GuardianTypo3\Infrastructure\Registry\Transport\PingTransportInterface;

final class RecordingPingTransport implements PingTransportInterface
{
    /** @var list<array{url: string, body: string}> */
    public array $sent = [];

    public function send(string $url, string $jsonBody): void
    {
        $this->sent[] = ['url' => $url, 'body' => $jsonBody];
    }
}
