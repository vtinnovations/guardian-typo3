<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\License;

/**
 * Immutable, structured outcome of the layered license evaluation.
 *
 * It combines the three independent checks — first-level raw-store integrity,
 * optional asymmetric signature, and the effective entitlement decision — into a
 * single value the rest of the application can reason about without knowing how
 * any individual check was performed. `status` carries a coarse, non-sensitive
 * label suitable for logging and generic administrator messaging; it never
 * encodes the reason a specific check failed.
 */
final class LicenseResult
{
    public function __construct(
        public readonly bool $integrityValid,
        public readonly bool $signatureValid,
        public readonly bool $licenseValid,
        public readonly string $status,
    ) {
    }

    public static function ok(string $status): self
    {
        return new self(true, true, true, $status);
    }

    /** A generic, reason-free integrity failure. */
    public static function integrityFailure(): self
    {
        return new self(false, false, false, 'invalid');
    }

    public function isFullyValid(): bool
    {
        return $this->integrityValid && $this->signatureValid && $this->licenseValid;
    }
}
