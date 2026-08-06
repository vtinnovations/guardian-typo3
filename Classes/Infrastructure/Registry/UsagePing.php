<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Registry;

use Vtinnovations\GuardianTypo3\Domain\Configuration\ServiceRecord;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\Transport\PingTransportInterface;

/**
 * Tells the vendor, at most once per web invocation, that this project ran here.
 *
 * The body is exactly two fields — the project name and this installation's
 * normalised host — and nothing else. No key, no record, no user, no session, no
 * cookie, no address list, no path, no environment, no request body and no
 * database content leaves the installation through this call. It is a plain
 * server-to-server notice, documented as such rather than disguised as something
 * else.
 *
 * It is deferred to the end of the request so it never delays a response, it is
 * guarded so repeated service resolution cannot fire it twice, it is skipped
 * entirely outside a web invocation, and its success or failure has no bearing
 * whatsoever on whether the installation is entitled.
 */
final class UsagePing
{
    /** Fires at most once per process, whichever collaborator arms it first. */
    private static bool $fired = false;

    /**
     * @param bool $immediate test seam: send synchronously so a fake transport
     *                        can observe the call without a shutdown hook
     */
    public function __construct(
        private readonly PingTransportInterface $transport,
        private readonly ServiceEndpoint $endpoint,
        private readonly bool $immediate = false,
    ) {
    }

    public function arm(string $host): void
    {
        if (self::$fired || $host === '') {
            return;
        }
        if (!$this->immediate && \PHP_SAPI === 'cli') {
            return; // the trigger is a web invocation, not a console run
        }
        self::$fired = true;

        $body = json_encode(
            ['project' => ServiceRecord::PROJECT, 'domain' => $host],
            \JSON_UNESCAPED_SLASHES
        );
        if ($body === false) {
            return;
        }
        $url = $this->endpoint->signal();

        if (!$this->immediate && \function_exists('register_shutdown_function')) {
            register_shutdown_function(function () use ($url, $body): void {
                $this->deliver($url, $body);
            });

            return;
        }
        $this->deliver($url, $body);
    }

    private function deliver(string $url, string $body): void
    {
        try {
            $this->transport->send($url, $body);
        } catch (\Throwable) {
            // silent and non-blocking by design
        }
    }

    /** @internal test helper */
    public static function resetForTesting(): void
    {
        self::$fired = false;
    }
}
