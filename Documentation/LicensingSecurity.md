# Entitlement security model (internal)

This is the security rationale behind the implementation described in
`LicensingImplementation.md`. Nothing here is hidden functionality: every
outbound call, every stored file and every check is documented.

## Trust model

The only thing that makes a record trustworthy is a **signature made by a private
key that exists solely on vendor infrastructure**. The distributed package
contains public verification keys and nothing else — it can check a signature and
cannot produce one. There is no embedded API password, no shared secret and no
bearer token anywhere in the trust path, because a secret shipped to every
customer is not a secret.

Three signatures are verified, under the issuer's two published rules:

| Signature | Rule | Covers | Names a key? |
|---|---|---|---|
| Record document | `vt-one/canonical-json-v1` | every field except its own `signature` | no |
| Integrity envelope | `vt-one/canonical-json-v1` | project, slug, version, exact-byte MD5, generation time, key id, algorithm | yes |
| Inbound HTTP request | `vt-one/request-sig-v1` | method, exact path, request id, timestamp, nonce, SHA-256 of the raw body | yes, in a header |

### Canonical form

These are **the issuer's formats, not Guardian's**. Other client bundles already
verify against them and licences have been signed with them, so Guardian
transcribes them rather than defining its own.

`vt-one/canonical-json-v1`: strip `signature`, sort object keys ascending and
recursively, leave list order alone, then `json_encode` with
`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` and no pretty-print. List order
matters: `license_features` is the only list in the contract and its order is
meaningful, so a client that sorted it would reject every genuine licence. JSON
distinguishes `false` from `"false"` and `null` from `0` on its own, so no
additional type tagging is needed.

`vt-one/request-sig-v1`: six values joined with newlines — uppercased method,
exact path served, request id, timestamp as a decimal string, one-use value, and
the lowercase hex SHA-256 of the raw body bytes. The key id selects which key to
verify with; it is deliberately **not** signed.

The record names no key, so its signature is verified against every key the build
holds. That is what keeps a record signed before a key rotation verifying after
it.

Fixed vectors are pinned in
`Tests/Unit/Infrastructure/Manifest/CanonicalFormTest.php`, and
`Tests/Unit/Integration/IssuerInteroperabilityTest.php` re-derives the same bytes
from an independent transcription of the issuer's rules — so a drift on either
side fails locally rather than on a customer's installation.

## The MD5 question

MD5 appears in the protocol, and it is worth being precise about what it does.

It is an **exact-byte tripwire**, compared in constant time against the digest of
the bytes actually held. It is *not* proof of origin, and it is never trusted on
its own: the envelope carrying it is verified first, so an attacker who edits the
document and recomputes the digest has produced an envelope that no longer
verifies. MD5's collision weakness does not help here, because the value is not
what establishes trust.

The bytes are never re-serialised before the digest is taken. Reformatting the
same JSON — even changing only whitespace — breaks it, which is exactly the
intent.

## Exact-host binding

Every entry in a licence is **one** normalized host name. `example.com`,
`www.example.com`, `shop.example.com` and `admin.shop.example.com` are four
different identities, and none is accepted in place of another. A literal
wildcard is rejected rather than interpreted.

`Domain/Environment/HostIdentity` contains no suffix, substring or pattern
matching at all — there is no code path that could accidentally widen a binding,
so `malicious-example.com` can never satisfy `example.com`. Normalisation changes
representation only: lower case, one trailing dot, a port, and IDN to Punycode.
It never strips `www`, never collapses to a registrable domain, and never
resolves an alias.

A licence may cover several hosts, and the set that it covers is signed:
`license_domains`. Authorisation is membership of that set and nothing else — the
allowance in `license_max_domains` is informational, `9999` is not a wildcard, and
no relationship between two names (apex and `www`, parent and child, siblings,
nesting, a shared suffix) substitutes for an entry.

Activation and refresh require the packet's `domain` to equal the signed
`license_domain`, which must itself be a member of `license_domains`. A push
additionally requires the body's `domain` to equal the signed one and to be a host
this installation is configured to serve. Entitlement, on every evaluation,
requires one exact member of both the signed set and the configured inventory.

Copying `license.json` and its envelope to an installation whose site
configuration names none of the signed hosts therefore fails before any capability
is granted, even though the signature itself still verifies.

The issuer binds the same way for this product. Its licence server carries a
per-product domain-binding contract, and Guardian's product (`guardian` /
`vt-guardian`) is registered as `exact_host`, so the host it signs into
`license_domain` is the host that activated — `www.` included. Other products
keep the historical apex-alias behaviour, so nothing already issued changes
meaning.

### Where the hosts come from

The host currently being served comes only from TYPO3's normalised request
parameters, re-checked against the installation's `trustedHostsPattern`. A `Host`,
`Forwarded` or `X-Forwarded-Host` value supplied by a client cannot select a
different identity. An unset pattern denies everything rather than accepting
anything.

The set this installation is configured to serve comes only from TYPO3 Site
Configuration, read through `SiteFinder`: each site's `base`, each language
`base`, and each `baseVariants` entry. That is the half of the decision a caller
cannot influence, which is why it — and not the request — is what the signed set
is intersected with. An installation with no site configuration has an empty
inventory, and an empty inventory is a refusal, never a pass.

Non-HTTP execution — console commands, the scheduler, queue workers — is held to
the same two sets as a request. Site configuration answers without a request, so
the intersection is evaluated rather than skipped, and the host last observed
being served (recorded in `var/guardian/installation.json`) only decides which of
several qualifying hosts is preferred. A worker on an installation whose
configuration no longer names an authorised host is granted nothing.

## The public endpoint

`/rest/api/v1/guardian-license-updater` is deliberately not protected by a TYPO3
backend route token: that mechanism protects interactive browser sessions, and
this caller is a server. Authentication is cryptographic and nothing else.

A claimed origin proves nothing and takes no part in the decision — `Origin`,
`Referer`, the user agent, the source address and reverse DNS are all
attacker-controlled or spoofable. An operator may add mutual TLS or a network
allow-list in front of the endpoint; that is defence in depth, never a
substitute.

The signed headers must equal the duplicated body fields, so the two halves of
the request cannot disagree. Every refusal returns the same `{"status":"error"}`,
so the endpoint cannot be used as a verification oracle. `GET` returns 405 with
`Allow: POST` — not 404, which would hide whether the endpoint exists.

### Replay and idempotency

- A narrow ±300 s window rejects stale and future-dated requests.
- One-use values are remembered **by digest**; the value itself is never stored.
- A request identifier is remembered with a fingerprint of the content it
  arrived with. The same identifier and the same content is an honest retry and
  returns `already_processed` without applying anything twice. The same
  identifier with *different* content is a conflict and is refused.
- The claim is taken under the same exclusive lock that later records the result,
  so two copies of one push cannot both decide they are first.
- A refused push releases its claim (the nonce stays consumed), so a corrected
  retry is not blocked by a reservation that did nothing.

**Clustered deployments:** the journal is a file in the shared `var/` directory.
If nodes do not share that filesystem, it must be replaced with an adapter over a
shared transactional store, or the endpoint must be pinned to one node.

## Failure behaviour

Nothing destructive happens on failure. No file is deleted, no state is
corrupted, no redirect loop is created, no remote command is executed, and no
unrelated functionality degrades. Capabilities that genuinely need the licence
stop; data and configuration are untouched; administrators are told which stage
refused, without being shown any packet material (see below).

A network error, timeout, TLS failure or vendor 5xx **never** removes a working
record. That is asserted directly in
`Tests/Unit/Application/Configuration/ActivationServiceTest.php`.

## Log and response secrecy

Ordinary logs may contain the operation, a coarse result category, the HTTP
status and the applied record version. They may not contain — and are tested not
to contain — the full key, any key fingerprint or length, the raw or Base64
payload, the MD5, any signature, a nonce, an authentication header, a request or
response body, or any request/response digest.

Two tests enforce this: one drives the real successful and failing flows with a
capturing logger and asserts the values are absent; the other reads the source
and fails if any logging call so much as references a prohibited field name.

## Administrator error reporting

A failure is reported with a stable machine code and a sentence naming the stage
that refused. Collapsing everything into one message was actively harmful: "the
licence could not be verified" reads identically whether this build is missing
the vendor key, the licence belongs to another domain, or V-T.ONE is simply down
— and those need completely different responses.

`Domain/Configuration/VerificationDiagnosis` is the single place that maps an
internal category to a public code and message. The codes an administrator may
see include:

| Code | Meaning |
|---|---|
| `signing_key_store_empty` | This build carries no approved V-T.ONE verification key |
| `unknown_signing_key` | The licence names a key this build does not carry |
| `signing_key_retired` | The key is outside its rotation window |
| `signature_invalid` | The signature did not match the signed content |
| `integrity_signature_invalid` | The integrity envelope did not verify |
| `response_schema_invalid` | V-T.ONE returned an unusable response |
| `request_correlation_failed` | The response did not match the request sent |
| `server_clock_skew` | The local clock differs too much from V-T.ONE |
| `license_dates_invalid` | The document's validity dates are not acceptable |
| `domain_mismatch` | No configured host is in the licence's signed set |
| `no_configured_domain` | The installation has no TYPO3 site configuration to license |
| `license_refresh_required` | The stored licence predates the signed domain set |
| `license_domains_missing` | V-T.ONE returned a licence with no domain set; the stored one was kept |
| `license_key_rejected` | V-T.ONE refused the key |
| `license_version_older` | An older licence was returned; the stored one was kept |
| `license_storage_failed` | The verified licence could not be written |
| `remote_verification_failed` | V-T.ONE could not be reached |

The code travels to the backend as `verification_code`. It is deliberately not
called `code`, because the AJAX layer already uses that key for the outcome of
the request itself and PHP's array union would silently drop one of them.

What a code never contains is the material: no licence key, signature, digest,
canonical bytes, key bytes, raw response or authentication data. This applies to
the authenticated backend interface only — the public machine-facing endpoint
still answers every refusal with an identical `{"status":"error"}`.

## Release hardening — and its limits

`Build/release.php` refuses to build while the pinned key ring is empty, strips
tests, fixtures, build tooling and development documentation, removes comments
and formatting from shipped PHP, writes a SHA-256 manifest of the
security-relevant files, and then verifies the artefact: every file parses, the
manifest matches, no development content survived, and nothing capable of signing
is present.

**What it does not do is rename private symbols, and that is a deliberate
limitation.** A TYPO3 extension is wired by fully-qualified class name — the
service container, the middleware stack, the console command registry and PSR-4
autoloading all resolve classes by name. Renaming would have to keep every one of
those in step across two major TYPO3 versions, and a mistake would break
installations in ways that are hard to diagnose. The structural hardening is
therefore the distribution of responsibilities across the source tree, verified
by `Tests/Unit/Integration/SourceLayoutTest.php`, and this limitation is stated
rather than papered over.

No claim is made that the result cannot be reverse-engineered. Distributed code
can always be read. What the design ensures is that reading it does not help:
without the vendor's private key, no attacker can produce a package that
verifies.

## What is deliberately absent

- `eval`, dynamic execution, self-modifying code, or source generated from a
  request.
- Any route that recalculates a digest and patches executable source.
- Remotely downloaded or executed code.
- Any path taken from a request; every stored file name is a constant.
- Disabled TLS, followed redirects, or protocol downgrade.
- Destructive anti-tamper behaviour.
- Any collection beyond the documented project-and-host signal.

## Key state and the remaining dependency

**The approved vendor verification key is pinned in this build.** The ring in
`Infrastructure/Version/ReleaseKeyring::material()` carries `vtone-2026a`
(Ed25519, fingerprint `edcd614e70c59ce0`), its declared fingerprint is
cross-checked by `productionReadiness()`, and `Build/release.php` therefore
packages. Both the shipped state and the gate that enforces it are asserted by
`ReleaseKeyringTest`, `ProfileImportTest` and `ReleaseArtefactTest`.

**What is still outstanding is interoperability evidence.**
`Tests/Unit/Support/ProductionVectors.php` is empty, so no fixed vector produced
by V-T.ONE has yet been replayed through this client's verification path.
`ProductionVectorTest` reports that gap rather than passing silently: a pinned key
proves what to trust, and a vector proves both sides build the same signing input.
Until one is supplied, cross-system compatibility rests on the shared 2026a
profile rather than on a test in this repository.

`signing_key_store_empty` means the client reached signature verification with no
key available — a build defect, not a bad key or a bad domain. The remedy is to
pin the approved public key and confirm it against a fixed signed vector — never
to bypass verification, accept the packet unsigned, trust the MD5 alone, or take
the key from the packet being verified.

### Exactly what is still required from V-T.ONE

Public material only. No private signing key may ever be supplied to, or stored
in, this repository.

1. ~~The approved Ed25519 **public** key(s), Base64-encoded, with the exact
   `key_id` string and algorithm identifier V-T.ONE puts on the wire.~~
   *Supplied: `vtone-2026a`, Ed25519, pinned and fingerprint-checked.*
2. ~~Which purposes each key covers: licence document, integrity envelope,
   updater request — or an explicit statement that one key covers all three.~~
   *Supplied: one key covers all three purposes.*
3. ~~Activation date and retirement date per key, if rotation is defined.~~
   *Supplied: active from 0, no retirement declared.*
4. For each key and purpose, a fixed interoperability vector: the exact
   canonical bytes V-T.ONE signed (Base64) plus the matching detached signature.
   This is what proves both sides build the same signing input; a key alone does
   not.
5. One verbatim successful `activate` response and one `refresh` response.

Items 1–3 are in `Infrastructure/Version/ReleaseKeyring::material()`. Items 4–5
belong in `Tests/Unit/Support/ProductionVectors` and are still outstanding;
`ProductionVectorTest` reports that as incomplete, so an empty set cannot be
mistaken for a verified one.
