# Testing

Guardian's Phase 1 tests are **CMS-independent unit tests**: they exercise pure
`Domain/` logic and extend `PHPUnit\Framework\TestCase` directly, so they do
**not** bootstrap TYPO3 and run identically regardless of TYPO3 13.4 or 14.

> ⚠️ These tests have **not** been executed in the environment where the scaffold
> was produced (no PHP/Composer/TYPO3 runtime is available there). The commands
> below are what to run in a proper environment. Do not treat them as having
> passed.

## What is covered (Phase 1)

Pure, deterministic logic — the highest-value, framework-free surface:

| Area | Test |
|---|---|
| Runtime config validation | `Tests/Unit/Domain/Configuration/RuntimeConfigurationTest.php` |
| Safe path normalisation & containment | `Tests/Unit/Domain/Filesystem/PathNormalizerTest.php` |
| Schedule due/next-run math | `Tests/Unit/Domain/Schedule/ScheduleEvaluatorTest.php` |
| Archive traversal (zip/tar-slip) | `Tests/Unit/Domain/Archive/ArchiveEntryValidatorTest.php` |
| Shell-free command construction | `Tests/Unit/Domain/Process/CommandRequestTest.php` |
| Job state transitions | `Tests/Unit/Domain/Job/JobTest.php` |
| Lock behaviour (real temp file) | `Tests/Unit/Infrastructure/Lock/FlockLockTest.php` |

Because these do not depend on TYPO3, a single run validates the logic for both
supported TYPO3 lines.

The suite also covers entitlement and licence handling, backend wiring, logging
redaction and the release artefact. Those tests are not itemised here: the table
above is an orientation aid for contributors working on the operational parts of
the product, and enumerating the entitlement tests would describe internals this
documentation deliberately leaves out. `vendor/bin/phpunit` runs everything.

## Dependency ranges

`composer.json` (`require-dev`) intentionally spans both TYPO3 eras so Composer can
resolve the toolchain that matches the installed core:

```json
"require-dev": {
    "phpunit/phpunit": "^10.5 || ^11.0",
    "typo3/testing-framework": "^8.2.3 || ^9.0"
}
```

- `typo3/testing-framework ^8.2.3` covers the TYPO3 13.4 line; `^9.0` covers the
  TYPO3 14 line. Composer selects the one compatible with the installed core.
- PHPUnit `^10.5 || ^11.0` is compatible with both testing-framework majors, and
  with the `#[Test]` / `#[DataProvider]` attributes used in the tests.
- `phpunit.xml.dist` uses only attributes valid in both PHPUnit 10.5 and 11.

## Commands (run in a real PHP/Composer environment)

```bash
# Install dependencies
composer install

# Run the CMS-independent unit tests
vendor/bin/phpunit -c phpunit.xml.dist

# Verify the minimum constraints actually resolve (TYPO3 13.4.9 / PHP 8.2 baseline)
composer update --prefer-lowest --prefer-stable
vendor/bin/phpunit -c phpunit.xml.dist
```

## CI matrix (to be executed in isolated installs)

Each cell is a fresh Composer install pinned to the given TYPO3 line, then the
unit suite (and, in later phases, TYPO3 functional tests):

| # | TYPO3 | PHP | Composer flags | Purpose |
|---|---|---|---|---|
| 1 | 13.4.9 | 8.2 | `--prefer-lowest --prefer-stable` | absolute minimum baseline |
| 2 | latest 13.4.x | 8.3 (or 8.4) | default | current 13.4 LTS |
| 3 | lowest 14.0 | core's PHP floor | default | 14 entry point |
| 4 | latest 14.x | latest supported PHP | default | current 14 line |

Example of pinning a cell before installing:

```bash
composer require --dev --no-update "typo3/cms-core:^13.4.9" "typo3/cms-backend:^13.4.9"
composer update --prefer-lowest --prefer-stable
```

## Functional tests (later phases)

When destructive features arrive, TYPO3 functional tests will be added under
`Tests/Functional/` using `typo3/testing-framework`, with a separate
`Build/FunctionalTests.xml`. They will run in the same CI matrix. None exist in
Phase 1 because there is no runtime behaviour (no writes, no process execution) to
exercise against a booted TYPO3.

## Runtime checks still pending

- Backend-module smoke test on a real **TYPO3 13.4.9** and a real **14.x** install
  (both modules visible to admins under *System*; all sections render; DI compiles;
  icon/labels/XLF resolve).
- **Site Configuration** reading against a real installation: the inventory is
  built from `SiteFinder` (site `base`, language `base`, `baseVariants`), which the
  unit suite exercises through a double rather than through TYPO3.
- **Backend session claim** against a real `be_sessions` row: the once-per-session
  behaviour is exercised here through a double, so the TYPO3 session read/write
  pair and the lock around it still want one live check with two parallel tabs.
- **Live V-T.ONE interoperability**: `Tests/Unit/Support/ProductionVectors.php` is
  still empty, so no vector produced by V-T.ONE has been replayed through this
  client's verification path.
