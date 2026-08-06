<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Manifest;

use Vtinnovations\GuardianTypo3\Domain\Configuration\ServiceRecord;

/**
 * What came out of {@see SealedPackage::open()}.
 *
 * An opened result hands over the three things that only make sense together:
 * the exact bytes to store, the parsed view of them, and the authenticated
 * envelope that vouches for those bytes. A refusal carries only a coarse
 * category — never the payload, the digest or a signature — so it can be recorded
 * without leaking packet contents.
 */
final class SealedPackageResult
{
    /**
     * @param array<string, mixed> $envelope
     */
    private function __construct(
        public readonly bool $trusted,
        public readonly string $category,
        public readonly ?ServiceRecord $record = null,
        public readonly string $documentBytes = '',
        public readonly array $envelope = [],
    ) {
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public static function opened(ServiceRecord $record, string $documentBytes, array $envelope): self
    {
        return new self(true, 'trusted', $record, $documentBytes, $envelope);
    }

    public static function refused(string $category): self
    {
        return new self(false, $category);
    }
}
