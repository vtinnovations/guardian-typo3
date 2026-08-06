<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Tests\Unit\Support;

/**
 * Fixed interoperability vectors captured from the real V-T.ONE service.
 *
 * The rest of the suite mints its own packages with a throwaway key pair. That
 * proves Guardian is self-consistent, which is necessary but not sufficient:
 * a client can be perfectly self-consistent and still disagree with the server
 * about field order, type tagging or context prefixes, and the first symptom
 * would be every real licence failing to verify.
 *
 * These vectors close that gap. Each one is a genuine signed artefact produced by
 * V-T.ONE, replayed through Guardian's production verification path. They contain
 * public material only — a public key, a signature, and the bytes that were
 * signed. **No private signing material belongs in this file, ever.**
 *
 * The set is empty until V-T.ONE supplies it. `ProductionVectorTest` reports the
 * gap rather than passing silently, so an empty set can never be mistaken for a
 * verified one.
 *
 * To populate, add one entry per accepted key ID and purpose:
 *
 *     [
 *         'label'        => 'activate/2026a',
 *         'key_id'       => 'vtone-2026a',
 *         'algorithm'    => 'ed25519',
 *         'public_key'   => '<base64 32-byte Ed25519 public key>',
 *         'purpose'      => 'record',            // record | envelope | request
 *         'canonical'    => '<base64 of the exact canonical bytes V-T.ONE signed>',
 *         'signature'    => '<base64 detached signature>',
 *         'expected'     => true,                // false for a negative vector
 *     ]
 */
final class ProductionVectors
{
    /**
     * @return list<array{
     *     label: string,
     *     key_id: string,
     *     algorithm: string,
     *     public_key: string,
     *     purpose: string,
     *     canonical: string,
     *     signature: string,
     *     expected: bool
     * }>
     */
    public static function all(): array
    {
        return [];
    }

    /**
     * Complete signed responses as V-T.ONE actually returns them, used to prove
     * Guardian accepts the real packet shape for both administrator flows.
     *
     * Each entry is the verbatim decoded JSON body of a `POST /api/v1/verify`
     * response, together with the host it was issued for and the public key that
     * signs it.
     *
     * @return array{activate: array<string, mixed>|null, refresh: array<string, mixed>|null}
     */
    public static function responses(): array
    {
        return ['activate' => null, 'refresh' => null];
    }

    public static function isPopulated(): bool
    {
        return self::all() !== [];
    }

    /** Exactly what is still needed, for the message a skipped test prints. */
    public static function missingMaterial(): string
    {
        return implode("\n", [
            'No V-T.ONE interoperability vectors are available. Required from the',
            'V-T.ONE licensing maintainer (public material only):',
            '  1. approved Ed25519 public key(s), Base64, with exact key_id and algorithm id;',
            '  2. purposes each key covers (record document / integrity envelope / updater request);',
            '  3. activation date and retirement date per key, if rotation is defined;',
            '  4. for each key and purpose: the exact canonical bytes V-T.ONE signed',
            '     (Base64) and the matching detached signature (Base64);',
            '  5. one verbatim successful activate response and one refresh response.',
        ]);
    }
}
