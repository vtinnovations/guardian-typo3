<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Application\Contract;

use Vtinnovations\GuardianTypo3\Domain\Configuration\StoredRecord;

/**
 * Persistence port for the installation's service record.
 *
 * The stored artefact is a pair — the exact document bytes and the authenticated
 * envelope that vouches for them — and the port only ever moves the two together.
 * There is deliberately no operation that writes a document without its envelope,
 * so the two can never drift apart.
 *
 * The verified host is kept separately because it is a local observation about
 * *this* installation rather than a vendor statement. Copying the stored pair to
 * another machine therefore does not carry that observation's meaning with it:
 * the first request on the new host records a different name and the pair stops
 * validating.
 */
interface ServiceRecordStoreInterface
{
    /**
     * Reads and fully re-validates the stored pair. A missing, unreadable,
     * mismatched, unsigned or tampered pair yields an untrusted result rather
     * than an exception.
     */
    public function read(int $now): StoredRecord;

    /**
     * Replaces the stored pair as one transaction: validate, stage, back up,
     * activate, re-read, and roll back atomically if the activated state does
     * not verify.
     *
     * @param array<string, mixed> $envelope
     *
     * @throws \Vtinnovations\GuardianTypo3\Domain\Exception\GuardianException when the pair could not be activated
     */
    public function replace(string $documentBytes, array $envelope, int $now): void;

    /** Removes the stored pair. Leaves unrelated working state untouched. */
    public function discard(): void;

    /** The host this installation was last observed to be serving, if known. */
    public function verifiedHost(): string;

    /**
     * Records the host this installation is serving. Called only with a host
     * resolved from trusted request data, never from a protocol packet.
     */
    public function rememberVerifiedHost(string $host): void;
}
