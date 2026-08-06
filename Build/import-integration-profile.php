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
 * Pins the verification keys from an issuer integration profile.
 *
 * The issuer exports its profile with:
 *
 *     vt:license:integration-profile --project-slug=guardian --product-id=vt-guardian \
 *         --format=json --output=<file>
 *
 * and this consumes exactly that file. Doing it this way rather than by hand is what stops the
 * two failure modes that actually happen: pinning a key from the wrong product's profile, and
 * pinning a key whose fingerprint nobody ever checked.
 *
 * Public material only. The profile contains no secret, and this refuses anything that looks like
 * one.
 *
 * Usage:
 *   php Build/import-integration-profile.php --profile=<file> [--expect-fingerprint=<sha256:16>] [--dry-run]
 *
 * The fingerprint should come from a different channel than the profile itself. Without it the
 * import still records the fingerprint the profile carries, but nobody has confirmed that the
 * profile is the one the issuer sent — so supply it when you have it.
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

$profilePath = trim((string) ($options['profile'] ?? ''));
$expected = strtolower(trim((string) ($options['expect-fingerprint'] ?? '')));
$dryRun = isset($options['dry-run']);

if ($profilePath === '') {
    $fail('--profile=<file> is required.');
}
if (!is_file($profilePath) || !is_readable($profilePath)) {
    $fail('the profile could not be read: ' . $profilePath);
}
if ($expected !== '' && preg_match('/^[0-9a-f]{16}$/', $expected) !== 1) {
    $fail('--expect-fingerprint must be the first 16 hexadecimal characters of the SHA-256 of the raw key.');
}

// ── Read and check the profile ──────────────────────────────────────────────

try {
    $profile = json_decode((string) file_get_contents($profilePath), true, 32, \JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    $fail('the profile is not valid JSON: ' . $e->getMessage());
}
if (!\is_array($profile)) {
    $fail('the profile is not a JSON object.');
}

// A profile carrying anything secret was exported wrongly and must not be used.
$raw = (string) file_get_contents($profilePath);
foreach (['secret_key', 'private_key', 'PRIVATE KEY', 'seed'] as $forbidden) {
    if (stripos($raw, $forbidden) !== false) {
        $fail('the profile contains "' . $forbidden . '". A verification profile carries public material only — do not use this file, and tell the issuer.');
    }
}

// The profile must be for this product, when it says which product it is for.
$product = $profile['product'] ?? [];
if (\is_array($product) && $product !== []) {
    $slug = (string) ($product['project_slug'] ?? '');
    $code = (string) ($product['product_id'] ?? '');
    if ($slug !== '' && $slug !== 'guardian') {
        $fail(sprintf('this profile is for project "%s", not "guardian".', $slug));
    }
    if ($code !== '' && $code !== 'vt-guardian') {
        $fail(sprintf('this profile is for product "%s", not "vt-guardian".', $code));
    }
}

$keys = $profile['keys'] ?? null;
if (!\is_array($keys) || $keys === []) {
    $fail('the profile contains no keys.');
}

// The canonicalisation the profile names must be the one this build implements. A profile
// announcing a different rule means the issuer changed the contract, and pinning its key would
// produce a build that verifies nothing.
$expectedRules = ['vt-one/canonical-json-v1', 'vt-one/request-sig-v1'];
foreach (($profile['signatures'] ?? []) as $domain => $definition) {
    $rule = (string) ($definition['canonicalisation'] ?? '');
    if ($rule !== '' && !\in_array($rule, $expectedRules, true)) {
        $fail(sprintf('the profile names an unsupported canonicalisation for "%s": %s', (string) $domain, $rule));
    }
}

// ── Turn the profile into pinned material ───────────────────────────────────

$entries = [];
$fingerprints = [];
$reported = [];

foreach ($keys as $index => $key) {
    if (!\is_array($key)) {
        $fail('key entry ' . $index . ' is not an object.');
    }

    $keyId = trim((string) ($key['key_id'] ?? ''));
    $algorithm = strtolower(trim((string) ($key['signature_algorithm'] ?? '')));
    $encoded = trim((string) ($key['public_key_base64'] ?? ''));
    $status = strtolower(trim((string) ($key['status'] ?? 'active')));

    if ($keyId === '' || $encoded === '') {
        $fail('key entry ' . $index . ' is missing its key_id or public key.');
    }
    if ($algorithm !== 'ed25519') {
        $fail(sprintf('key "%s" uses algorithm "%s"; only ed25519 is accepted.', $keyId, $algorithm));
    }

    $decoded = base64_decode($encoded, true);
    if ($decoded === false) {
        $fail(sprintf('the public key for "%s" is not valid Base64.', $keyId));
    }
    if (\strlen($decoded) === 64) {
        $fail(sprintf('the value for "%s" decodes to 64 bytes, which is a private key. Do not use this profile.', $keyId));
    }
    if (\strlen($decoded) !== 32) {
        $fail(sprintf('the public key for "%s" decodes to %d bytes, not 32.', $keyId, \strlen($decoded)));
    }

    $fingerprint = substr(hash('sha256', $decoded), 0, 16);

    // When the profile states a fingerprint, it must describe the key beside it.
    $claimed = strtolower(trim((string) ($key['fingerprint_short'] ?? '')));
    if ($claimed !== '' && !hash_equals($claimed, $fingerprint)) {
        $fail(sprintf('the profile\'s own fingerprint for "%s" does not match its key. The file is inconsistent; do not use it.', $keyId));
    }

    $canonical = base64_encode($decoded);
    $half = (int) ceil(\strlen($canonical) / 2);

    $entries[] = sprintf(
        "            ['%s', 'ed25519', '%s' . '%s', ['*'], 0, null],",
        $keyId,
        substr($canonical, 0, $half),
        substr($canonical, $half),
    );
    $fingerprints[] = sprintf("            '%s' => '%s',", $keyId, $fingerprint);
    $reported[] = ['id' => $keyId, 'status' => $status, 'fingerprint' => $fingerprint];
}

// The out-of-band check, when one was supplied: at least one imported key must be the one the
// issuer confirmed separately.
if ($expected !== '') {
    $matched = false;
    foreach ($reported as $entry) {
        if (hash_equals($expected, $entry['fingerprint'])) {
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        $fail(
            "no key in this profile matches the fingerprint you supplied.\n"
            . '    supplied:   ' . $expected . "\n"
            . '    in profile: ' . implode(', ', array_column($reported, 'fingerprint')) . "\n"
            . '    Do not pin these keys. Confirm both values with the issuer.'
        );
    }
}

// ── Report ──────────────────────────────────────────────────────────────────

fwrite(\STDOUT, "\n  Keys in this profile:\n");
foreach ($reported as $entry) {
    fwrite(\STDOUT, sprintf("    %-24s %-9s fingerprint %s\n", $entry['id'], $entry['status'], $entry['fingerprint']));
}

if ($dryRun) {
    fwrite(\STDOUT, "\n  Dry run: nothing was written.\n"
        . "  Confirm a fingerprint with the issuer through a separate channel, then run again\n"
        . "  with --expect-fingerprint=<value> and without --dry-run.\n\n");
    exit(0);
}

if ($expected === '') {
    fwrite(\STDOUT, "\n  NOTE: no --expect-fingerprint was supplied, so nothing has confirmed that this\n"
        . "  profile is the one the issuer sent. Prefer confirming it out of band.\n");
}

// ── Write ───────────────────────────────────────────────────────────────────

$source = @file_get_contents($target);
if (!\is_string($source)) {
    $fail('the key ring could not be read: ' . $target);
}

$materialBody = "        return [\n" . implode("\n", $entries) . "\n        ];";
$fingerprintBody = "        return [\n" . implode("\n", $fingerprints) . "\n        ];";

$patched = preg_replace(
    '/(private static function material\(\): array\s*\{\s*)return \[\];/s',
    '$1' . str_replace('$', '\\$', ltrim($materialBody)),
    $source,
    1,
    $materialCount
);
$patched = preg_replace(
    '/(private static function declaredFingerprints\(\): array\s*\{\s*)return \[\];/s',
    '$1' . str_replace('$', '\\$', ltrim($fingerprintBody)),
    (string) $patched,
    1,
    $fingerprintCount
);

if ($materialCount !== 1 || $fingerprintCount !== 1) {
    $fail('the key ring is not in its empty state — it already holds pinned material. Reset it before importing, so an import never silently replaces a key that is already trusted.');
}

if (@file_put_contents($target, $patched) === false) {
    $fail('the key ring could not be written: ' . $target);
}

fwrite(\STDOUT, "\n  Pinned " . \count($entries) . " key(s). Now run:\n    php Build/release.php\n\n");
