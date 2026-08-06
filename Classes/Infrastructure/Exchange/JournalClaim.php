<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Exchange;

/**
 * The answer to "may I process this request?".
 *
 * Granted means the caller holds the claim and must settle it. Seen means the
 * identifier is already on record: the caller compares the stored fingerprint
 * with the one it computed to decide between an honest repeat and a conflicting
 * reuse. Refused means the claim could not be taken at all — a one-use value
 * already consumed, or the journal itself unavailable — and is always treated as
 * a rejection rather than as permission.
 */
final class JournalClaim
{
    private function __construct(
        public readonly bool $granted,
        public readonly bool $known,
        public readonly string $fingerprint = '',
        public readonly string $result = '',
        public readonly ?int $version = null,
    ) {
    }

    public static function granted(): self
    {
        return new self(true, false);
    }

    public static function seen(string $fingerprint, string $result, ?int $version): self
    {
        return new self(false, true, $fingerprint, $result, $version);
    }

    public static function refused(): self
    {
        return new self(false, false);
    }

    /** Whether a repeat presented exactly the content the original carried. */
    public function matches(string $fingerprint): bool
    {
        return $this->fingerprint !== '' && hash_equals($this->fingerprint, $fingerprint);
    }
}
