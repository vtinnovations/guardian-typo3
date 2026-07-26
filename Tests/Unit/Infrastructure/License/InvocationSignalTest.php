<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Infrastructure\License;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vtinnovations\GuardianTypo3\Infrastructure\License\InvocationSignal;
use Vtinnovations\GuardianTypo3\Infrastructure\License\Signal\SignalTransportInterface;

final class InvocationSignalTest extends TestCase
{
    protected function setUp(): void
    {
        InvocationSignal::resetForTesting();
    }

    protected function tearDown(): void
    {
        InvocationSignal::resetForTesting();
    }

    private function recorder(): SignalTransportInterface
    {
        return new class implements SignalTransportInterface {
            /** @var list<array{url:string, body:string}> */
            public array $calls = [];
            public function send(string $url, string $jsonBody): void { $this->calls[] = ['url' => $url, 'body' => $jsonBody]; }
        };
    }

    #[Test]
    public function endpointReconstructsToTheExactUrl(): void
    {
        $signal = new InvocationSignal($this->recorder(), true);
        self::assertSame('https://www.v-t.one/rest/api/v1/log-envoke', $signal->endpoint());
    }

    #[Test]
    public function firesAtMostOnceWithOnlyProjectAndDomain(): void
    {
        $transport = $this->recorder();
        $signal = new InvocationSignal($transport, true);

        $signal->arm('Guardian', 'example.com');
        $signal->arm('Guardian', 'example.com'); // repeated resolution / repeated call
        $signal->arm('Other', 'other.com');

        self::assertCount(1, $transport->calls);
        self::assertSame('https://www.v-t.one/rest/api/v1/log-envoke', $transport->calls[0]['url']);
        self::assertSame(['project' => 'Guardian', 'domain' => 'example.com'], json_decode($transport->calls[0]['body'], true));
    }

    #[Test]
    public function transmitsNothingBeyondProjectAndDomain(): void
    {
        $transport = $this->recorder();
        (new InvocationSignal($transport, true))->arm('Guardian', 'example.com');

        self::assertSame(['project', 'domain'], array_keys((array) json_decode($transport->calls[0]['body'], true)));
    }

    #[Test]
    public function doesNotFireWithoutADomain(): void
    {
        $transport = $this->recorder();
        (new InvocationSignal($transport, true))->arm('Guardian', '');
        self::assertCount(0, $transport->calls);
    }

    #[Test]
    public function transportFailureIsSwallowed(): void
    {
        $throwing = new class implements SignalTransportInterface {
            public function send(string $url, string $jsonBody): void { throw new \RuntimeException('down'); }
        };

        (new InvocationSignal($throwing, true))->arm('Guardian', 'example.com');
        $this->addToAssertionCount(1); // reaching here means no exception escaped
    }
}
