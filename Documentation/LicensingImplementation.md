# Guardian licensing implementation

## Protocol

Guardian verifies licenses server-side with the canonical V&T Innovations
verification endpoint. The request is a JSON `POST` containing `key`, `domain`,
`product` (`vt-guardian`) and, when requested by a future entitlement check,
`require_package`. The authentication credential remains private inside the
TYPO3 HTTP adapter and is never included in Fluid variables, JavaScript config,
logs or JSON responses. TYPO3's `RequestFactory` supplies proxy and TLS policy;
TLS verification remains enabled and redirects are disabled.

## Contao-to-TYPO3 mapping

| Original Contao component | TYPO3 implementation | Responsibility |
|---|---|---|
| `Security/LicenseVerifier` | `Infrastructure/License/VtOneLicenseVerifier` | Remote protocol, timeout and response normalization |
| verifier result array | `Domain/License/LicenseVerificationResult` | Typed valid, denied or unreachable result |
| `Security/LicenseManager` | `Application/License/LicenseManager` | Activation, refresh, grace, expiry and removal |
| manager JSON helpers | `JsonLicenseStateRepository` | Atomic private persistence in `var/guardian/license.json` |
| cached manager array | immutable `LicenseState` | Key, timestamps, binding, package and validation status |
| `Security/LicenseGuard` | `Application/License/LicenseGuard` | Server-side Free/Pro entitlement queries |
| `Controller/LicenseController` | `GuardianAjaxController` license actions | Admin-only status, activate and clear endpoints |
| Twig license JavaScript | `guardian.js` license functions | Masked status display and immediate UI gating |

## Entitlement rules

- A successful verification is trusted for seven days unless its server expiry
  is in the past.
- A successful verification older than 24 hours is marked stale for a future
  background refresh.
- Only normalized package `pro` grants Pro. Every other currently valid package
  is interpreted as Free.
- A failed first activation retains only the rejected key and never receives a
  grace period.
- Explicit denial during refresh revokes the cached verification immediately.
- Transport, timeout, HTTP 5xx and malformed-response failures preserve an
  existing successful cache until grace or hard expiry ends.
- Removing a license deletes the local `license.json` file.

The raw key is persisted only in the private Guardian working directory. Backend
responses expose a masked preview, never the raw key or API credential.
