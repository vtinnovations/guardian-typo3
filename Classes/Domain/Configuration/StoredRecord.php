<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Configuration;

/**
 * The result of reading the stored pair back.
 *
 * A record is present only when the bytes on disk still verify against their
 * envelope, so callers never have to ask a second question before trusting it.
 * `category` explains an absence — nothing stored, a broken pair, an unusable
 * key — and is safe to record; it carries no packet content.
 */
final class StoredRecord
{
    private function __construct(
        public readonly ?ServiceRecord $record,
        public readonly string $category,
    ) {
    }

    public static function of(ServiceRecord $record): self
    {
        return new self($record, 'trusted');
    }

    public static function none(string $category = 'absent'): self
    {
        return new self(null, $category);
    }

    public function exists(): bool
    {
        return $this->record !== null;
    }
}
