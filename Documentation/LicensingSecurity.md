# Licensing & tamper-resistance (internal)

This document describes the licensing hardening layered onto Guardian's existing
online license system. It is disclosed here deliberately — nothing below is
hidden functionality.

## Existing architecture (unchanged, authoritative)

- **Store:** `<project>/var/guardian/license.json` — a locally cached
  *verification result* (schema: `license_key`, `license_verified_at`,
  `license_issued_at`, `license_expires_at`, `license_domain`, `license_package`,
  `validation_status`). It is written by the activation/refresh flow, so it is NOT
  a static certificate.
- **Local-first validity:** the license start (`license_issued_at`) and expiry
  (`license_expires_at`) dates are stored in the JSON and are authoritative.
  `currentStatus()` checks validity purely from these stored dates and makes **no
  remote call** — a verified license keeps working offline until it genuinely
  expires (or before its start date it reports `not_started`). The server is only
  contacted on explicit **activation** (to obtain the dates) and on an explicit
  **refresh**; there is no periodic re-verification. `cache_stale` remains an
  informational flag only.
- **Central check:** `Application\License\LicenseManager::currentStatus()`.
- **Online verification:** `Infrastructure\License\VtOneLicenseVerifier` →
  `POST https://www.v-t.one/api/v1/verify` (via TYPO3 `RequestFactory`).
- **Entitlement:** `LicenseState` (pure rules), `LicenseTier` (none/free/pro),
  `LicenseGuard`, and controller `requireLicensed()/requirePro()` gates.

The online verifier remains the authoritative entitlement control. The layers
below add tamper resistance and operational telemetry without replacing it.

## Validation order (`LicenseManager`)

1. Emit the invocation signal (once per request — see below).
2. Load the cached state.
3. **First-level raw-store integrity check** (`StoreIntegritySentinel`). Fails
   closed only when *pinned* (see lifecycle). Unpinned = passive monitor.
4. Parse / interpret the state (existing rules: key, domain, expiry, grace, tier).
5. **Optional asymmetric signature check** (`SignatureSentinel`, `evaluate()`
   only) when a `signature` field and an embedded public key are both present.
6. Return a single result — `LicenseStatus` for the existing UI/API, or the
   structured `LicenseResult` (`integrityValid`, `signatureValid`, `licenseValid`,
   `status`) from `evaluate()`.

## First-level integrity (MD5 over raw bytes)

`StoreIntegritySentinel::intact()` reads the exact bytes of `license.json`,
computes the MD5, and constant-time-compares (`hash_equals`) it against an
expected value **reconstructed at runtime** from split, XOR-folded, base64,
reordered fragments held in the class. The expected value is never a single
32-char literal and is never in config, env, the DB, cache or the JSON itself.
A mismatch (or missing/unreadable store, when pinned) fails the check and logs a
single generic note (`Licensing integrity check did not pass.`) via the normal
`SystemLoggerInterface`. No checksum, construction detail or precise reason is
ever exposed.

MD5 is only a first-level indicator, not proof of authenticity.

### Checksum lifecycle (pinning)

Because an embedded checksum binds the code to one exact file version, and the
online model *rewrites* `license.json` at runtime, the sentinel ships **unpinned**
by default (fragments reconstruct to an empty string → passive monitor, never
blocks a live license). Pinning is only appropriate for deployments that **freeze**
their license file (offline/static licensing):

```
vendor/bin/typo3 guardian:license:digest /absolute/path/to/frozen/license.json
```

The isolated developer command PRINTS ready-to-paste `pieces()` and `order()`
method bodies for `StoreIntegritySentinel`. It only prints — it never writes
source and the running application never regenerates the value itself. After
pinning, any change to the exact bytes trips the integrity check.

## Optional signature layer (Ed25519)

`SignatureSentinel::verified()` applies only when the payload carries a
`signature` AND a public key is pinned (`pkA()`/`pkB()`, split base64) AND
libsodium is present; otherwise it is "not applicable" and passes (it is never
the sole strong control). It verifies a detached Ed25519 signature over the
deterministic canonical JSON of the payload with the `signature` field excluded.
Only the **public** key is ever embedded; the private signing key is never in the
repository or the distributed package.

To rotate the signing key: generate a new Ed25519 keypair off-repository, re-issue
signed certificates with the new private key, and update `pkA()/pkB()` with the
new public key (base64, split) in the release.

## Invocation signal

`InvocationSignal` sends **one** `POST` per request to
`https://www.v-t.one/rest/api/v1/log-envoke` with a JSON body containing **only**:

```json
{ "project": "Guardian", "domain": "example.com" }
```

`Content-Type: application/json`, `Accept: application/json`. The domain is the
normalized running host (`DomainNormalizer`, never a raw unvalidated header).

Behaviour: fired from `LicenseManager::currentStatus()` at most once per request
(process-wide guard, so repeated service resolution cannot duplicate it); deferred
to `register_shutdown_function` so it never delays the response; not sent from CLI;
native cURL (`CurlSignalTransport`) with `CONNECTTIMEOUT_MS=1500`,
`TIMEOUT_MS=2500`, `NOSIGNAL`, no redirects, `SSL_VERIFYPEER=true`,
`SSL_VERIFYHOST=2`; the response body is never read; the handle is always closed;
all failures are swallowed. It never displays output, redirects, blocks
functionality, or alters the license result. **Nothing** other than `project` and
`domain` is transmitted (no keys, JSON contents, user data, IPs, paths, env,
cookies or sessions). The endpoint is reconstructed from fragments; to change it,
adjust `endpoint()`.

## Failure behaviour

On integrity/license failure the license is marked invalid and only
license-gated functionality is disabled. No data is deleted, no unrelated config
changed, no fatal error in public requests, no redirect loops, and no internal
checksum/signature/obfuscation detail revealed. Administrators see a generic,
actionable message; a generic status is logged.

## Source-level hardening & release build

- Neutral internal names (`StoreIntegritySentinel`, `InvocationSignal`,
  `SignatureSentinel`, `SignalTransportInterface`), split constants, reversible
  transforms across a few small methods, and harmless decoys. Integrity
  comparison, endpoint construction and failure handling are deliberately not
  co-located in one method. DI registrations and public APIs remain valid and
  maintainable.
- **Release obfuscation (process, not run here):** build a distributable package
  that excludes `Tests/` and dev tools, preserves TYPO3/Symfony attributes,
  service names, DI config, autoloading and public APIs, runs the test suite
  against the built package, produces a reproducible archive with a file-checksum
  manifest, and never includes private signing keys. Obfuscation is a delay, not
  a guarantee — assume determined inspection is possible.

## Security limitations

- MD5 integrity is a tripwire, not cryptographic proof.
- In the online model the sentinel is a monitor unless a frozen file is pinned.
- The signature layer is only strong when a certificate + pinned public key are
  deployed; the embedded public key can be read (that is expected — it is public).
- Obfuscation raises the bar for casual tampering only.

## Files

- Domain: `LicenseResult`, `DomainNormalizer`.
- Infrastructure/License: `StoreIntegritySentinel`, `SignatureSentinel`,
  `LicenseStoreReader`, `InvocationSignal`, `Signal/SignalTransportInterface`,
  `Signal/CurlSignalTransport`.
- Application: `LicenseManager` (extended, backward compatible).
- Command: `LicenseDigestCommand` (`guardian:license:digest`).
