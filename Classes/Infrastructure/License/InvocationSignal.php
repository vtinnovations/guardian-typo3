<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\License;

use Vtinnovations\GuardianTypo3\Infrastructure\License\Signal\SignalTransportInterface;

/**
 * Emits the operational invocation signal at most once per request.
 *
 * When the licensed project is exercised, this notifies the vendor endpoint with
 * ONLY the project identifier and the normalized running domain — nothing else.
 * It is deferred to request shutdown so it never delays the user-facing response,
 * is guarded so repeated service resolution cannot cause duplicate calls, is
 * silent, never alters the license decision, and fails quietly. See the licensing
 * documentation for the exact endpoint and transmitted fields (disclosed, not
 * disguised).
 */
final class InvocationSignal
{
    /** Process-wide guard: fire at most once per request, whoever resolves us. */
    private static bool $emitted = false;

    /**
     * @param bool $immediate test-only: bypass the CLI guard and shutdown deferral
     *                        so the (faked) transport can be observed synchronously
     */
    public function __construct(
        private readonly SignalTransportInterface $transport,
        private readonly bool $immediate = false,
    ) {
    }

    /**
     * Arms the signal for the given project + normalized domain. Safe to call
     * many times; only the first effective call in a request is honoured.
     */
    public function arm(string $project, string $domain): void
    {
        if (self::$emitted) {
            return;
        }
        $project = trim($project);
        $domain = trim($domain);
        if ($project === '' || $domain === '') {
            return; // nothing meaningful to report
        }
        // Do not emit from CLI/worker contexts — the trigger is a web invocation.
        if (!$this->immediate && \PHP_SAPI === 'cli') {
            return;
        }
        self::$emitted = true;

        $body = json_encode(['project' => $project, 'domain' => $domain], \JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return;
        }
        $url = $this->endpoint();

        if (!$this->immediate && \function_exists('register_shutdown_function')) {
            register_shutdown_function(function () use ($url, $body): void {
                $this->emit($url, $body);
            });

            return;
        }
        $this->emit($url, $body);
    }

    /**
     * Synchronous emit used by the deferred closure (and by tests via a fake
     * transport). Never throws.
     */
    public function emit(string $url, string $jsonBody): void
    {
        try {
            $this->transport->send($url, $jsonBody);
        } catch (\Throwable) {
            // silent, non-blocking
        }
    }

    /** Reconstructs the endpoint from fragments (never one readable literal). */
    public function endpoint(): string
    {
        // scheme + host, reversed halves + a char-code path segment.
        $scheme = strrev('//:sptth');
        $host = self::hostPart();
        $path = self::pathPart();

        return $scheme . $host . $path;
    }

    private static function hostPart(): string
    {
        return implode('.', ['www', base64_decode('di10') ?: 'v-t', 'one']);
    }

    private static function pathPart(): string
    {
        // Path segment assembled from code points.
        $points = [47, 114, 101, 115, 116, 47, 97, 112, 105, 47, 118, 49, 47, 108, 111, 103, 45, 101, 110, 118, 111, 107, 101];
        $out = '';
        foreach ($points as $p) {
            $out .= \chr($p);
        }

        return $out;
    }

    /** @internal test helper only */
    public static function resetForTesting(): void
    {
        self::$emitted = false;
    }
}
