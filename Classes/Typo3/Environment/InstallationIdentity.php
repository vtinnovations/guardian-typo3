<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Typo3\Environment;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use Vtinnovations\GuardianTypo3\Application\Contract\InstallationIdentityInterface;
use Vtinnovations\GuardianTypo3\Application\Contract\ServiceRecordStoreInterface;
use Vtinnovations\GuardianTypo3\Domain\Environment\HostIdentity;

/**
 * TYPO3 adapter that establishes the installation's host identity.
 *
 * The host comes from TYPO3's normalised request parameters, which already
 * account for the installation's reverse-proxy configuration. TYPO3's own
 * host-header verification runs earlier in the middleware stack and would have
 * rejected an untrusted value outright; the installation's trusted-host pattern
 * is nevertheless re-applied here rather than assumed, so a `Host`, `Forwarded`
 * or `X-Forwarded-Host` value supplied by a client cannot make this installation
 * present itself as a different one even if this code is reached by some other
 * route. A host that does not survive both that check and canonical
 * normalisation yields no identity at all.
 *
 * Whenever a live request does establish the identity, it is written down. That
 * note is what lets a console command, the scheduler or a queue worker be held to
 * the same host as the web front end instead of quietly skipping the check — and
 * it is also why moving the stored record to another machine does not carry its
 * meaning along: the first request there records a different name.
 */
final class InstallationIdentity implements InstallationIdentityInterface
{
    /** The pattern value that means "accept anything" in TYPO3's configuration. */
    private const ALLOW_ALL = '.*';

    /** The pattern value that means "must equal SERVER_NAME". */
    private const MATCH_SERVER_NAME = 'SERVER_NAME';

    private ?string $resolved = null;
    private bool $live = false;

    public function __construct(
        private readonly ServiceRecordStoreInterface $store,
    ) {
    }

    public function current(): string
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $host = $this->fromCurrentRequest();
        if ($host !== '') {
            $this->live = true;
            $this->store->rememberVerifiedHost($host);

            return $this->resolved = $host;
        }

        $this->live = false;

        return $this->resolved = $this->store->verifiedHost();
    }

    public function isLive(): bool
    {
        $this->current();

        return $this->live;
    }

    public function resolveFrom(ServerRequestInterface $request): string
    {
        $params = $request->getAttribute('normalizedParams');
        $candidate = $params instanceof NormalizedParams ? $params->getHttpHost() : '';
        if ($candidate === '') {
            // Fall back to the URI the framework built, never to a raw header.
            $candidate = $request->getUri()->getHost();
        }
        if ($candidate === '' || !$this->isTrusted($candidate, $request->getServerParams())) {
            return '';
        }

        return HostIdentity::normalize($candidate);
    }

    private function fromCurrentRequest(): string
    {
        if (\PHP_SAPI === 'cli') {
            return '';
        }
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;

        return $request instanceof ServerRequestInterface ? $this->resolveFrom($request) : '';
    }

    /**
     * Applies the installation's own trusted-host policy, with the same meaning
     * TYPO3 gives it: an unset pattern denies everything (the configuration is
     * incomplete), `.*` accepts anything, `SERVER_NAME` requires the value to
     * match what the server itself reports, and anything else is an anchored,
     * case-insensitive pattern.
     *
     * @param array<string, mixed> $serverParams
     */
    private function isTrusted(string $candidate, array $serverParams): bool
    {
        $pattern = $GLOBALS['TYPO3_CONF_VARS']['SYS']['trustedHostsPattern'] ?? '';
        if (!\is_string($pattern) || $pattern === '') {
            return false;
        }
        if ($pattern === self::ALLOW_ALL) {
            return true;
        }

        if ($pattern === self::MATCH_SERVER_NAME) {
            $serverName = (string) ($serverParams['SERVER_NAME'] ?? '');
            if ($serverName === '') {
                return false;
            }
            $port = (string) ($serverParams['SERVER_PORT'] ?? '');
            $secure = ($serverParams['HTTPS'] ?? '') !== '' && strtolower((string) $serverParams['HTTPS']) !== 'off';
            $parts = explode(':', $candidate, 2);
            if (strtolower($parts[0]) !== strtolower($serverName)) {
                return false;
            }

            // A port in the header must be the port actually being served.
            return !isset($parts[1])
                ? ($port === '' || $port === ($secure ? '443' : '80'))
                : $parts[1] === $port;
        }

        return preg_match('/^' . $pattern . '$/i', $candidate) === 1;
    }
}
