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

/**
 * The two fixed destinations Guardian ever contacts about entitlement, and the
 * transport limits that apply to them.
 *
 * Both addresses are compiled into the build. Nothing selects them at runtime:
 * not configuration, not a request parameter, not a database value and not a
 * previous answer from the service. They are assembled from parts rather than
 * written as single literals, which keeps them from being trivially rewritten in
 * a shipped artefact; that is a packaging measure and nothing more, since the
 * real protection is that a substituted destination still cannot produce a
 * validly signed package.
 *
 * Redirects are refused rather than followed, so a 3xx answer can never move a
 * request to another host, scheme or port.
 */
final class ServiceEndpoint
{
    /** Short, deliberate ceilings. Entitlement traffic is tiny. */
    public const CONNECT_TIMEOUT_SECONDS = 5.0;
    public const TOTAL_TIMEOUT_SECONDS = 8.0;
    public const MAX_RESPONSE_BYTES = 262144;
    public const MEDIA_TYPE = 'application/json';

    /** Where a first activation and an administrator refresh are sent. */
    public function exchange(): string
    {
        return $this->scheme() . $this->authority() . $this->join(['api', 'v1', 'verify']);
    }

    /** Where the one-way invocation signal is sent. */
    public function signal(): string
    {
        return $this->scheme() . $this->authority() . $this->join(['rest', 'api', 'v1', 'log-envoke']);
    }

    /**
     * The path this installation exposes for vendor-initiated pushes. It is
     * public and documented, so it stays readable — the secrecy of a path is not
     * part of this design.
     */
    public function inboundPath(): string
    {
        return $this->join(['rest', 'api', 'v1', 'guardian-license-updater']);
    }

    private function scheme(): string
    {
        return strrev('//:sptth');
    }

    private function authority(): string
    {
        return implode('.', ['www', 'v' . chr(45) . 't', 'one']);
    }

    /**
     * @param list<string> $segments
     */
    private function join(array $segments): string
    {
        return '/' . implode('/', $segments);
    }
}
