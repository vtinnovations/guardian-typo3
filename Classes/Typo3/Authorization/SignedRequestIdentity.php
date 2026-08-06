<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Typo3\Authorization;

/**
 * The outcome of authenticating an inbound machine request.
 *
 * The identifying values it carries are only meaningful once `authenticated` is
 * true, so a caller cannot accidentally act on metadata from a request that was
 * never proven. The category explains a refusal for the installation's own
 * records; it is never sent back to the caller.
 */
final class SignedRequestIdentity
{
    private function __construct(
        public readonly bool $authenticated,
        public readonly string $category,
        public readonly string $requestId = '',
        public readonly string $nonce = '',
        public readonly string $keyId = '',
        public readonly int $timestamp = 0,
    ) {
    }

    public static function accepted(string $requestId, string $nonce, string $keyId, int $timestamp): self
    {
        return new self(true, 'authenticated', $requestId, $nonce, $keyId, $timestamp);
    }

    public static function rejected(string $category): self
    {
        return new self(false, $category);
    }

    /**
     * A digest of the one-use value. The value itself is never stored or logged;
     * this is enough to recognise it if it is ever presented again.
     */
    public function nonceDigest(): string
    {
        return hash('sha256', $this->nonce);
    }
}
