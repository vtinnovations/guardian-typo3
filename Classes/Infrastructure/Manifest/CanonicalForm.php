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

/**
 * Rebuilds the exact bytes the issuer signed.
 *
 * These are not Guardian's formats to choose. They are the issuer's published rules —
 * `vt-one/canonical-json-v1` for documents and envelopes, `vt-one/request-sig-v1` for the update
 * push — and other client bundles already verify against them, so the definition below is a
 * transcription rather than a design.
 *
 * ## vt-one/canonical-json-v1
 *
 *   1. object keys sorted ascending, recursively;
 *   2. arrays keep their given order — never sorted;
 *   3. `json_encode` with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, no pretty-print;
 *   4. the `signature` key is removed first, so a signature never signs itself.
 *
 * Rule 2 matters more than it looks: `license_features` is the only list in the contract, and its
 * order is meaningful. A client that sorted it would produce different bytes and reject every
 * genuine licence.
 *
 * JSON keeps types apart on its own — `false` and `"false"` do not encode alike — so nothing extra
 * is needed to stop one value impersonating another.
 *
 * ## vt-one/request-sig-v1
 *
 * Six values joined with newlines: the uppercased method, the exact path served, the request id,
 * the timestamp as a decimal string, the one-use value, and the lowercase hex SHA-256 of the raw
 * body bytes. The key id is deliberately **not** part of it — it selects the key, it is not signed
 * material.
 *
 * The whole document is canonicalised as it arrived, not against a field list held here. The issuer
 * signs the fields it emitted; imposing a local list would mean a future added field silently
 * breaks verification. Whether the fields are *acceptable* is a separate question, answered by
 * {@see \Vtinnovations\GuardianTypo3\Domain\Configuration\ServiceRecord}.
 */
final class CanonicalForm
{
    /** The issuer's identifiers for the two rules, quoted in its integration profile. */
    public const DOCUMENT_RULE = 'vt-one/canonical-json-v1';
    public const REQUEST_RULE = 'vt-one/request-sig-v1';

    /**
     * Signing input for a record document.
     *
     * @param array<string, mixed> $document
     */
    public function document(array $document): string
    {
        return $this->canonicalJson($document);
    }

    /**
     * Signing input for the integrity envelope. Same rule as the document — one canonicalisation
     * covers both, which is why the issuer publishes it once.
     *
     * @param array<string, mixed> $envelope
     */
    public function envelope(array $envelope): string
    {
        return $this->canonicalJson($envelope);
    }

    /**
     * Signing input for an inbound update push.
     *
     * Binding the method and the path stops a captured packet being replayed at another endpoint;
     * binding the body digest stops it being edited on the way.
     */
    public function request(
        string $method,
        string $path,
        string $requestId,
        int $timestamp,
        string $nonce,
        string $bodySha256Hex,
    ): string {
        return implode("\n", [
            strtoupper($method),
            $path,
            $requestId,
            (string) $timestamp,
            $nonce,
            strtolower($bodySha256Hex),
        ]);
    }

    /**
     * `vt-one/canonical-json-v1`. Returns an empty string when the structure cannot be encoded,
     * which the caller treats as "cannot verify" rather than as a passing check.
     *
     * @param array<string, mixed> $payload
     */
    private function canonicalJson(array $payload): string
    {
        unset($payload['signature']);

        try {
            return json_encode(
                $this->sortObjectKeys($payload),
                \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            return '';
        }
    }

    /** Sorts object keys ascending, recursively, leaving list order untouched. */
    private function sortObjectKeys(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortObjectKeys($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sortObjectKeys($item), $value);
    }
}
