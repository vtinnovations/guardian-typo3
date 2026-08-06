<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

/**
 * Pins an approved V-T.ONE public verification key into this build.
 *
 * Editing the key ring by hand is how the wrong bytes get trusted: a truncated
 * paste, a Base64URL variant, or — worst — a 64-byte value, which is a *private*
 * key and must never end up in a distributed product. This tool refuses all of
 * those, prints the fingerprint so it can be compared against the one the issuer
 * published through a different channel, and only then writes anything.
 *
 * It accepts the encodings an issuer actually sends: standard Base64, Base64URL,
 * or a PEM `-----BEGIN PUBLIC KEY-----` block, whose DER prefix is stripped.
 * Only the raw 32 bytes are ever pinned.
 *
 * This mirrors the pinning workflow already used by the sibling V-T.ONE client
 * bundle, so both products are provisioned the same way.
 *
 * Usage:
 *   php Build/pin-verification-key.php --key-id=vtone-2026a --public-key=<base64> --fingerprint=<sha256:16> [--dry-run]
 *   php Build/pin-verification-key.php --key-id=vtone-2026a --public-key=@/path/to/key.txt --fingerprint=<sha256:16>
 *
 * The fingerprint is mandatory. A key nobody cross-checked is a key nobody
 * verified, and the release gate refuses to package one.
 */

$root = \dirname(__DIR__);
$target = $root . '/Classes/Infrastructure/Version/ReleaseKeyring.php';

$fail = static function (string $message): never {
    fwrite(\STDERR, "\n  REFUSED: " . $message . "\n\n");
    exit(1);
};

// ── Arguments ───────────────────────────────────────────────────────────────

$options = [];
foreach (\array_slice($argv, 1) as $argument) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $argument, $matches) !== 1) {
        $fail('unrecognised argument: ' . $argument);
    }
    $options[$matches[1]] = $matches[2] ?? '1';
}

$keyId = trim((string) ($options['key-id'] ?? ''));
$supplied = (string) ($options['public-key'] ?? '');
$fingerprintClaim = strtolower(trim((string) ($options['fingerprint'] ?? '')));
$dryRun = isset($options['dry-run']);
$purposes = trim((string) ($options['purposes'] ?? '*'));

if ($keyId === '' || $supplied === '') {
    $fail('both --key-id and --public-key are required.');
}
if (preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $keyId) !== 1) {
    $fail('the key id must be a short selector of letters, digits, dot, underscore or hyphen.');
}
if (!$dryRun && $fingerprintClaim === '') {
    $fail('--fingerprint is required. Obtain it from the issuer through a different channel than the key itself, or use --dry-run to see the computed value first.');
}
if ($fingerprintClaim !== '' && preg_match('/^[0-9a-f]{16}$/', $fingerprintClaim) !== 1) {
    $fail('the fingerprint must be the first 16 hexadecimal characters of the SHA-256 of the raw key.');
}

// ── Read the key ────────────────────────────────────────────────────────────

if (str_starts_with($supplied, '@')) {
    $file = substr($supplied, 1);
    if (!is_file($file) || !is_readable($file)) {
        $fail('the key file could not be read: ' . $file);
    }
    $supplied = (string) file_get_contents($file);
}
$supplied = trim($supplied);

$raw = null;

if (str_contains($supplied, 'BEGIN PUBLIC KEY')) {
    // A PEM SubjectPublicKeyInfo block for Ed25519 is a 12-byte DER prefix
    // followed by the 32 raw bytes.
    $body = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $supplied) ?? '';
    $der = base64_decode($body, true);
    if (\is_string($der) && \strlen($der) === 44) {
        $raw = substr($der, 12);
    }
} elseif (str_contains($supplied, 'PRIVATE KEY')) {
    $fail('that is a private key. Only the public verification key may be pinned.');
}

if ($raw === null) {
    // Standard Base64, or the URL-safe alphabet some issuers use.
    $candidate = strtr($supplied, '-_', '+/');
    $decoded = base64_decode($candidate, true);
    if ($decoded === false) {
        $fail('the key is not valid Base64, Base64URL or PEM.');
    }
    $raw = $decoded;
}

if (\strlen($raw) === 64) {
    $fail('that value decodes to 64 bytes, which is an Ed25519 *private* key. It must never be pinned, and must never have been sent to you.');
}
if (\strlen($raw) !== 32) {
    $fail(sprintf('an Ed25519 public key decodes to 32 bytes, not %d.', \strlen($raw)));
}
if (rtrim($raw, "\x00") === '') {
    $fail('an all-zero value is not a verification key.');
}

$canonical = base64_encode($raw);
$fingerprint = substr(hash('sha256', $raw), 0, 16);

if ($fingerprintClaim !== '' && !hash_equals($fingerprintClaim, $fingerprint)) {
    $fail(sprintf(
        "the key does not match the fingerprint you supplied.\n"
        . "    supplied:   %s\n"
        . "    calculated: %s\n"
        . '    Do not pin this key. Confirm both values with the issuer.',
        $fingerprintClaim,
        $fingerprint
    ));
}

// ── Report, and stop here on a dry run ──────────────────────────────────────

fwrite(\STDOUT, sprintf(
    "\n  Key id:      %s\n  Algorithm:   ed25519\n  Purposes:    %s\n  Fingerprint: %s  (sha256, first 16)\n",
    $keyId,
    $purposes,
    $fingerprint
));

if ($dryRun) {
    fwrite(\STDOUT, "\n  Dry run: nothing was written.\n"
        . "  Compare the fingerprint with the value the issuer published, then run\n"
        . "  again with --fingerprint=" . $fingerprint . " and without --dry-run.\n\n");
    exit(0);
}

// ── Write ───────────────────────────────────────────────────────────────────

$source = @file_get_contents($target);
if (!\is_string($source)) {
    $fail('the key ring could not be read: ' . $target);
}

// Split so the complete key is not one greppable literal in the artefact. This
// is a packaging measure only; the key is public and its security rests
// entirely on the private half never leaving the issuer.
$half = (int) ceil(\strlen($canonical) / 2);
$first = substr($canonical, 0, $half);
$second = substr($canonical, $half);

$purposeList = implode(', ', array_map(
    static fn (string $purpose): string => "'" . trim($purpose) . "'",
    explode(',', $purposes)
));

$materialEntry = sprintf(
    "        return [\n            ['%s', 'ed25519', '%s' . '%s', [%s], 0, null],\n        ];",
    $keyId,
    $first,
    $second,
    $purposeList
);
$fingerprintEntry = sprintf(
    "        return [\n            '%s' => '%s',\n        ];",
    $keyId,
    $fingerprint
);

$patched = preg_replace(
    '/(private static function material\(\): array\s*\{\s*)return \[\];/s',
    '$1' . str_replace('$', '\\$', ltrim($materialEntry)),
    $source,
    1,
    $materialCount
);
$patched = preg_replace(
    '/(private static function declaredFingerprints\(\): array\s*\{\s*)return \[\];/s',
    '$1' . str_replace('$', '\\$', ltrim($fingerprintEntry)),
    (string) $patched,
    1,
    $fingerprintCount
);

if ($materialCount !== 1 || $fingerprintCount !== 1) {
    $fail('the key ring no longer has the expected empty structure; pin the key by hand and re-run the release check.');
}

if (@file_put_contents($target, $patched) === false) {
    $fail('the key ring could not be written: ' . $target);
}

fwrite(\STDOUT, "\n  Pinned. Now run:\n    php Build/release.php\n\n");
