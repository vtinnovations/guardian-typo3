# Guardian entitlement implementation

This describes how Guardian obtains, stores and enforces its entitlement, and
where each part of that lives in the source tree.

## The three exchanges

Guardian implements the V-T.ONE exchange protocol, revision 2. All three
exchanges carry a **complete signed package** — never a partial change — and all
three converge on the same verification and the same atomic storage.

### A. First activation

An administrator enters a key. Guardian posts to
`https://www.v-t.one/api/v1/verify`:

```json
{
  "action": "activate",
  "project": "Guardian",
  "project_slug": "guardian",
  "product_id": "vt-guardian",
  "license_key": "<entered key>",
  "domain": "<this installation's host>",
  "request_id": "<fresh, per request>",
  "timestamp": 1784880547,
  "nonce": "<fresh, one use>"
}
```

Nothing else is sent. The reply must quote back the same `request_id`, must
carry a plausible `server_time`, and must contain `license_payload_b64` together
with a signed `integrity` envelope.

### B. Update Licence

The same endpoint with `action=refresh` and `current_license_version`, using the
key already stored. The administrator never has to retype it, and it never
travels to the browser: pressing **Update Licence** with the field blank — or
with the same key re-entered — is recognised as a refresh.

### C. Vendor-initiated push

V-T.ONE posts to `https://<installation>/rest/api/v1/guardian-license-updater`
with `action=license_update`, the complete package, and five signed headers
(`X-VT-Request-ID`, `X-VT-Timestamp`, `X-VT-Nonce`, `X-VT-Key-ID`,
`X-VT-Signature`). The endpoint answers `updated`, `already_processed`, or a
bare `{"status":"error"}`.

## What "verified" means

Every package — from any of the three exchanges — is opened in this exact order,
and stops at the first failure:

1. the envelope is structurally complete;
2. the **envelope's signature verifies** — this is what makes the digest it
   carries mean anything at all;
3. `license_payload_b64` decodes under strict Base64 to bounded bytes;
4. the MD5 of **those exact bytes** matches the envelope, compared in constant
   time;
5. the bytes are parsed — and never re-serialised, because re-serialising would
   produce different bytes and break both the digest and the signature;
6. the document's own signature verifies under `vt-one/canonical-json-v1`,
   against every pinned key, because the record names none;
7. every protocol invariant holds (product, exact host, dates, lifetime rule,
   package, features, status, version);
8. the envelope and the document agree about product and version.

MD5 is a byte-level tripwire carried inside an authenticated envelope. It is
never treated as evidence of origin, and recomputing it over altered bytes does
not help, because the envelope that names it is signed.

## The signed host set

A schema-2 record carries three host fields, all of them signed:

```json
{
  "license_domain": "shop.example.com",
  "license_domains": ["example.com", "shop.example.com"],
  "license_max_domains": 3
}
```

- `license_domains` is the authorisation set: a non-empty, lexicographically
  sorted list of unique, already-canonical host names. It is **checked**, not
  repaired — sorting or de-duplicating it here would mean verifying a signature
  over one list and using another.
- `license_domain` is the host the current exchange was carried out for, and must
  be a member of that set.
- `license_max_domains` is the vendor's stated allowance. It is informational.
  `9999` is what the vendor reports for an instance-bound product and is **not** a
  wildcard, and a set larger than the allowance is still accepted — the vendor
  lowers an allowance without unbinding what is already bound.

Guardian never adds, removes or synthesises an entry. A wildcard entry, an
unsorted or duplicated list, a non-canonical name, or a `license_domain` outside
the set makes the whole document unusable.

A record issued before the vendor signed a host set is authentic and is kept — its
key is what fetches a current one — but it authorises nothing until a refresh has
succeeded. Guardian reports that as `license_refresh_required`. A *newly issued*
record without the fields is refused outright rather than stored in place of one
that works.

## Which host, and how it is decided

Two sets decide entitlement, and neither can be reached by whoever is asking:

- **What this installation is configured to be** — every host in TYPO3 Site
  Configuration: each site's `base`, each language `base`, and each `baseVariants`
  entry, read through `SiteFinder`. Never a `Host`, `Forwarded` or
  `X-Forwarded-Host` header.
- **What the vendor authorised** — the signed `license_domains`.

Entitlement requires one **exact** member of both. Several domains may be
configured on the installation; one intersection is enough. The host currently
being served wins when it qualifies, so a request, a console command and a queue
worker all settle on the same answer, which is recorded in the evaluation result
as the matched domain.

For an exchange, the host asked about is the one being served when it is
configured here, and the installation's primary configured host otherwise — so a
backend reached under its own hostname verifies the site it belongs to. An
installation with no site configuration has no host to be licensed for, and
activation is refused with `no_configured_domain`.

## Where things live

The responsibilities are distributed through the directories this extension
already uses. No single directory contains the flow, and no single registration
can switch it off.

| Responsibility | Location |
|---|---|
| Exact-host policy | `Domain/Environment/HostIdentity` |
| Tier vocabulary, evaluation result | `Domain/Environment/CapabilityTier`, `CapabilityGrant` |
| Record document and its invariants | `Domain/Configuration/ServiceRecord` |
| Exchange and intake outcomes | `Domain/Configuration/ProvisioningOutcome`, `RecordIntakeOutcome` |
| Canonical signing inputs | `Infrastructure/Manifest/CanonicalForm` |
| Signature verification | `Infrastructure/Manifest/DetachedSignature` |
| Package opening, digest, ordering | `Infrastructure/Manifest/SealedPackage` |
| Pinned verification keys | `Infrastructure/Version/ReleaseKeyring`, `SigningKey` |
| Atomic storage and rollback | `Infrastructure/Configuration/SealedRecordStore` |
| Replay and idempotency | `Infrastructure/Exchange/RequestJournal` |
| Fixed destinations | `Infrastructure/Registry/ServiceEndpoint` |
| Outbound protocol | `Infrastructure/Registry/RecordExchangeClient` |
| HTTPS transports | `Infrastructure/Registry/Transport/*` |
| Invocation signal | `Infrastructure/Registry/UsagePing` |
| Session module-entry notice | `Infrastructure/Registry/EntryNotice` |
| Configured-host set policy | `Domain/Environment/HostInventory` |
| Inbound request authentication | `Typo3/Authorization/SignedRequestAuthorization` |
| Installation identity | `Typo3/Environment/InstallationIdentity` |
| Configured-host inventory | `Typo3/Environment/SiteHostInventory` |
| Once-per-session claim | `Typo3/Environment/BackendSessionClaim` |
| This product's licence section | `Typo3/Backend/GuardianPackageSection` |
| Installed V-T.ONE products | `Application/Environment/PackageDirectory` |
| Shared licence screen | `Controller/Backend/PackageOverviewController` |
| Evaluation | `Application/Environment/EntitlementReader` |
| Feature gate | `Application/Environment/CapabilityAssertion` |
| Administrator flows | `Application/Configuration/ActivationService` |
| Applying a push | `Application/Configuration/RecordIntake` |
| Public route | `Middleware/RestEndpointMiddleware` |

## Storage

Two files in the project's private `var/guardian/` directory, activated together
as one transaction:

- `license.json` — the exact bytes the vendor signed, byte for byte;
- `license.seal.json` — the integrity envelope that vouches for those bytes.

A third file, `installation.json`, records the host this installation has been
observed serving. It is a local observation, not a vendor statement, and is what
lets console commands, the scheduler and queue workers be held to the same host
as the web front end.

A replacement stages both files beside their targets, flushes them, reads them
back, backs up the previous pair, renames both into place, and re-reads the live
result. If that final read does not verify, the backup is restored. A document
and an envelope can therefore never drift apart.

`exchange-journal.json` holds replay and idempotency state: request identifiers
with a fingerprint of the content they arrived with, and digests — never values —
of the one-use nonces.

## Entitlement rules

- A confirmed record works entirely offline until its own expiry. No network
  call takes part in the decision.
- One host must be an **exact** member of both the configured inventory and the
  signed `license_domains`. Apex, `www`, parent, child, sibling and nested names
  are different identities, and no allowance value substitutes for membership.
- The product is sold as `free` or `pro`, and both require an activated, signed
  record. `pro` grants Pro; `free` grants Free. A document naming any other
  package value is refused when it is parsed — it is not read as the smallest
  tier — so it can never be stored or refreshed into place.
- An expired **Pro** record keeps the Free feature set when, and only when, the
  vendor signed `free_available` into it. Nothing is synthesised: it is the same
  record, key and authorised hosts, evaluated one tier lower.
- An expired **Free** record has no lesser tier beneath it and grants nothing,
  whatever `free_available` says. Neither does an expired Pro record without the
  flag.
- A record the vendor marked `invalid`, `suspended` or `revoked` grants nothing.
- A confirmation older than 24 hours is flagged stale in the interface but does
  not withdraw anything.
- A transport failure, timeout, malformed answer or failed check never removes a
  working record.
- An explicit refusal of the key currently stored withdraws it. A refusal of some
  *other* key — a typo in a replacement — leaves the working record untouched.
- A refresh cannot move the record to an older version, and a push must be
  strictly newer.

## Gates

Entitlement is asserted where the privileged work happens, not only at the edge:

| Operation | Requires | Enforced in |
|---|---|---|
| Create a backup | Free | `Application/Backup/BackupService::create()` |
| Scheduled backups | Pro | `Application/Backup/ScheduledBackupRunner` |
| Run an update job | Pro | `Application/Update/UpdateJobRunner::run()` |
| Restore a backup | Pro | `Application/Recovery/RestoreService::restore()` |
| Backend actions | Free / Pro | `Controller/Backend/GuardianAjaxController` |
| `guardian:backup:run-due` | Pro | `Command/RunDueBackupsCommand` |
| `guardian:update:run` | Pro | `Command/RunUpdateJobCommand` |

The console commands and the detached update worker re-assert the requirement
themselves rather than trusting that whatever queued the work was authorised.

## Invocation signal

Once per web invocation, deferred to the end of the request, Guardian posts
exactly this to `https://www.v-t.one/rest/api/v1/log-envoke`:

```json
{"project":"Guardian","domain":"<normalized host>"}
```

Nothing else. No key, no record, no user, no session, no address, no path, no
environment. It uses native cURL with short deadlines, full TLS verification and
redirects refused; the answer is never read. Its success or failure has no
bearing on entitlement. This is documented here rather than disguised.

## Session module-entry notice

Separate from the per-invocation signal, and deliberately not merged with it.
When an administrator first opens the protected module in a signed-in backend
session, Guardian posts exactly this to the same endpoint:

```json
{"domain":"<matched host>","key":"<full licence key>"}
```

- It is armed from the module's own entry point — the Guardian module and the
  shared licence screen — never from reading entitlement, so it does not fire on
  an asset request, an AJAX poll, a console command, a queue worker or a frontend
  page.
- The key comes only from a record that has just verified against the vendor's
  signature. A tampered file yields no key and no call. A record that is authentic
  but currently withheld — expired, or not for this domain — still yields one.
- The host is the matched domain from the evaluation, so it does not depend on
  which URL the backend happens to be open at.
- The claim is taken in the backend user's server-side session data, under a short
  lock so two tabs opened together cannot both be first. It is claimed **before**
  delivery, so a timeout is not retried within the session. Signing out, the
  session expiring, or signing in again allows one more.
- The mark stores only that the product is spoken for: no key, no host, no session
  identifier, no payload.
- This is the only place a full key leaves the server. It never reaches the
  browser, the audit trail, a diagnostic or an exception.

## The shared licence screen

**System → VTOne Licensing** is a host, not a product page: one section per
installed V-T.ONE product, each headed `<Product> Licence management`, each
posting to that product's own endpoints. Guardian's own section is registered in
`ext_localconf.php`:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['vtone']['packages']['guardian'] = [
    'title'    => 'Guardian',
    'provider' => \Vtinnovations\GuardianTypo3\Typo3\Backend\GuardianPackageSection::class,
];
```

The module identifier `vtone_licensing` is the same string in every V-T.ONE
extension. TYPO3 merges module registrations by identifier, so installing several
of them produces one entry listing them all rather than one competing entry each.
A provider only has to offer `title()`, `slug()`, `state()` and `actions()`; it
does not have to depend on this extension. A product that cannot be resolved, or
whose section throws, is skipped rather than taking the screen down.

This screen is the **only** administrator-facing licensing surface in the
product. Guardian previously carried its own **Settings → Pro license** panel
driving the same endpoints; that panel, its script, its state rendering and its
labels have been removed, and Guardian's Settings tab now only links here. There
is one activate path, one update path and one remove path per product.

Each section offers four endpoints, all of them TYPO3 backend AJAX routes that
carry a route token and are asserted administrator-only in the controller:

| Control | Route | Flow |
|---|---|---|
| (initial state) | `guardian_license_status` | read-only projection, also rendered server-side |
| Verify and activate licence | `guardian_license_activate` | `action=activate` for an entered key |
| Update licence | `guardian_license_refresh` | `action=refresh` with `current_license_version`, using the stored key |
| Remove licence | `guardian_license_clear` | discards the stored record under lock |

The state under each heading is rendered by the server (`Partials/Packages/State`),
so the screen is correct on first paint and cannot sit in a loading state. After an
operation the server accepted, the page reloads, so what is displayed is what was
persisted — the script keeps no second copy of licence state and never addresses
V-T.ONE itself.

## Building a release

`php Build/release.php [target]` produces the distributable tree. It refuses to
run while no verification key is pinned, strips development content and
comments, writes a SHA-256 manifest, and verifies the result. See
`LicensingSecurity.md` for what it deliberately does not do.
