<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Domain\Configuration;

use Vtinnovations\GuardianTypo3\Domain\Environment\CapabilityTier;
use Vtinnovations\GuardianTypo3\Domain\Environment\HostIdentity;

/**
 * Immutable, strictly validated view of the authoritative service record.
 *
 * The record itself is a complete document issued and signed by the vendor; this
 * class never invents, defaults or repairs a value. {@see fromDocument()} either
 * returns a fully valid record or null — a document that is incomplete,
 * inconsistent, bound to a different product, or bound to a host that is not a
 * single exact name is rejected outright rather than degraded.
 *
 * Three dates are kept strictly separate and are never substituted for one
 * another:
 *   - issuedAt   — when the vendor created the record;
 *   - startsAt   — the first instant at which it is effective;
 *   - verifiedAt — the most recent successful confirmation.
 *
 * The parsed view is deliberately NOT the stored artefact. Storage keeps the
 * exact bytes received from the vendor, because the integrity digest and the
 * document signature are defined over those bytes; re-serialising this object
 * would produce different bytes and break both. Nothing here touches the clock,
 * the network or the filesystem: the caller supplies "now".
 */
final class ServiceRecord
{
    /** The only document schema this release understands. */
    public const SCHEMA_VERSION = 2;

    /** Stable product identity. All three must match a document exactly. */
    public const PROJECT = 'Guardian';
    public const PROJECT_SLUG = 'guardian';
    public const PRODUCT_ID = 'vt-guardian';

    /** Longest accepted activation key. */
    public const MAX_KEY_LENGTH = 190;

    /**
     * Upper bound on the size of the authorised host set. It is a sanity limit on
     * a signed document, not a licensing rule: the vendor's own allowance is
     * carried in `license_max_domains` and is never enforced here.
     */
    public const MAX_DOMAINS = 512;

    /**
     * Canonical field order. It defines the document signature input and must
     * stay byte-identical to the vendor's ordering.
     *
     * @var list<string>
     */
    public const CANONICAL_FIELDS = [
        'schema_version',
        'project',
        'project_slug',
        'license_key',
        'license_domain',
        'license_domains',
        'license_max_domains',
        'license_package',
        'license_features',
        'license_version',
        'license_issued_at',
        'license_starts_at',
        'license_expires_at',
        'license_lifetime',
        'license_verified_at',
        'free_available',
        'validation_status',
    ];

    /**
     * @param list<string>      $features
     * @param list<string>|null $domains  the signed authorised host set, or null
     *                                    for a record issued before the vendor
     *                                    began signing one
     */
    private function __construct(
        public readonly string $key,
        public readonly string $host,
        public readonly ?array $domains,
        public readonly ?int $maxDomains,
        public readonly string $package,
        public readonly array $features,
        public readonly int $version,
        public readonly int $issuedAt,
        public readonly int $startsAt,
        public readonly ?int $expiresAt,
        public readonly bool $lifetime,
        public readonly int $verifiedAt,
        /**
         * The vendor's signed fallback flag: whether this record keeps the Free
         * feature set once its paid term ends. It is authoritative and is never
         * assumed — an expired record without it entitles nothing.
         */
        public readonly bool $freeAvailable,
        public readonly string $signature,
        public readonly RecordStatus $status,
    ) {
    }

    /**
     * Parses a decoded record document. Returns null when any invariant of the
     * exchange protocol is violated.
     *
     * `$reason` receives a coarse label for *which* family of invariant failed,
     * so an administrator can be told whether the dates, the product or the host
     * were the problem instead of being told only that something was. It never
     * carries a value from the document.
     *
     * @param array<string, mixed> $document
     * @param 'structure'|'product'|'domain'|'dates'|null $reason
     */
    public static function fromDocument(array $document, ?string &$reason = null): ?self
    {
        $reason = null;

        if (($document['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return self::reject($reason, 'structure');
        }
        if (($document['project'] ?? null) !== self::PROJECT
            || ($document['project_slug'] ?? null) !== self::PROJECT_SLUG
        ) {
            return self::reject($reason, 'product');
        }

        $key = $document['license_key'] ?? null;
        if (!\is_string($key) || $key === '' || strlen($key) > self::MAX_KEY_LENGTH) {
            return self::reject($reason, 'structure');
        }

        // The bound host must already be a single canonical name. A value that
        // only becomes valid after normalisation is a mismatch between the two
        // sides of the protocol and is rejected rather than repaired.
        $rawHost = $document['license_domain'] ?? null;
        if (!\is_string($rawHost) || $rawHost === '' || HostIdentity::normalize($rawHost) !== $rawHost) {
            return self::reject($reason, 'domain');
        }

        // The authorised host set and the vendor's allowance travel together.
        // A document carrying one without the other is not a shape the vendor
        // emits, so it is refused rather than half-read.
        $hasSet = \array_key_exists('license_domains', $document);
        $hasAllowance = \array_key_exists('license_max_domains', $document);
        $domains = null;
        $maxDomains = null;
        if ($hasSet || $hasAllowance) {
            if (!$hasSet || !$hasAllowance) {
                return self::reject($reason, 'domain');
            }
            $domains = self::readDomainSet($document['license_domains']);
            $allowance = $document['license_max_domains'];
            if ($domains === null || !\is_int($allowance) || $allowance < 1) {
                return self::reject($reason, 'domain');
            }
            // The host this operation was carried out for must be one the vendor
            // actually authorised. Nothing else about the set is interpreted:
            // membership is the whole of the rule.
            if (!\in_array($rawHost, $domains, true)) {
                return self::reject($reason, 'domain');
            }
            // Deliberately absent: a check that the set is no larger than the
            // allowance. The vendor lowers an allowance without unbinding what
            // is already bound, and enforcing it here would take working
            // installations dark for a decision that is not this side's to make.
            $maxDomains = $allowance;
        }

        $package = $document['license_package'] ?? null;
        if (!\is_string($package) || $package === '' || $package !== strtolower($package)) {
            return self::reject($reason, 'structure');
        }
        // This product is sold as "free" or "pro". A document naming anything
        // else belongs to a different product, and what it would unlock here is
        // not this side's to guess, so it is refused at the edge rather than
        // read as the smallest tier. Refusing it early means it can never be
        // stored, refreshed into place, or asked about later.
        if (CapabilityTier::fromPackage($package) === CapabilityTier::None) {
            return self::reject($reason, 'product');
        }

        $features = self::readFeatureList($document['license_features'] ?? null);
        if ($features === null) {
            return self::reject($reason, 'structure');
        }

        $version = $document['license_version'] ?? null;
        if (!\is_int($version) || $version < 1) {
            return self::reject($reason, 'structure');
        }

        $issuedAt = self::readInstant($document['license_issued_at'] ?? null);
        $startsAt = self::readInstant($document['license_starts_at'] ?? null);
        if ($issuedAt === null || $startsAt === null) {
            return self::reject($reason, 'dates');
        }

        $lifetime = $document['license_lifetime'] ?? null;
        if (!\is_bool($lifetime)) {
            return self::reject($reason, 'dates');
        }

        // An expiry is mandatory unless the record is explicitly a lifetime one,
        // in which case it must be absent rather than merely falsy.
        $rawExpiry = $document['license_expires_at'] ?? null;
        if ($lifetime) {
            if ($rawExpiry !== null) {
                return self::reject($reason, 'dates');
            }
            $expiresAt = null;
        } else {
            $expiresAt = self::readInstant($rawExpiry);
            if ($expiresAt === null || $expiresAt < $startsAt) {
                return self::reject($reason, 'dates');
            }
        }

        $verifiedAt = $document['license_verified_at'] ?? null;
        if (!\is_int($verifiedAt) || $verifiedAt < 0) {
            return self::reject($reason, 'dates');
        }

        $freeAvailable = $document['free_available'] ?? null;
        if (!\is_bool($freeAvailable)) {
            return self::reject($reason, 'structure');
        }

        $signature = $document['signature'] ?? null;
        if (!\is_string($signature) || $signature === '') {
            return self::reject($reason, 'structure');
        }

        $status = RecordStatus::tryFrom((string) ($document['validation_status'] ?? ''));
        if ($status === null) {
            return self::reject($reason, 'structure');
        }

        return new self(
            key: $key,
            host: $rawHost,
            domains: $domains,
            maxDomains: $maxDomains,
            package: $package,
            features: $features,
            version: $version,
            issuedAt: $issuedAt,
            startsAt: $startsAt,
            expiresAt: $expiresAt,
            lifetime: $lifetime,
            verifiedAt: $verifiedAt,
            freeAvailable: $freeAvailable,
            signature: $signature,
            status: $status,
        );
    }

    /**
     * Whether this record predates the signed host set.
     *
     * Such a record is authentic — it verified against the vendor's signature
     * like any other — but it does not say which hosts it authorises, and that
     * answer is not this side's to invent. It is kept so its key can be used to
     * ask for a current one, and so nothing is lost if the exchange fails, but it
     * authorises nothing until it has been refreshed.
     */
    public function predatesDomainSet(): bool
    {
        return $this->domains === null;
    }

    /**
     * Whether the vendor authorised this exact host.
     *
     * Membership of the signed set is the entire test. The set is not widened by
     * an apex, a "www" form, a parent, a child, a sibling or an alias, and a large
     * allowance does not stand in for an entry — a host that is not in the set was
     * not authorised, whatever the allowance says.
     */
    public function authorizes(string $host): bool
    {
        if ($this->domains === null) {
            return false;
        }
        $candidate = HostIdentity::normalize($host);
        if ($candidate === '') {
            return false;
        }
        foreach ($this->domains as $authorized) {
            if (hash_equals($authorized, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The signed host set, empty for a record that predates it.
     *
     * @return list<string>
     */
    public function authorizedDomains(): array
    {
        return $this->domains ?? [];
    }

    /** Whether the record's own status field marks it as usable. */
    public function isMarkedValid(): bool
    {
        return $this->status === RecordStatus::Valid;
    }

    public function hasStarted(\DateTimeImmutable $now): bool
    {
        return $now->getTimestamp() >= $this->startsAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        if ($this->lifetime) {
            return false;
        }

        return $this->expiresAt !== null && $this->expiresAt < $now->getTimestamp();
    }

    /**
     * Entitlement from the record's own dates alone. No network call is made, so
     * a confirmed record keeps working offline until it genuinely expires.
     */
    public function isEffective(\DateTimeImmutable $now): bool
    {
        if (!$this->isMarkedValid()) {
            return false;
        }

        return $this->hasStarted($now) && !$this->isExpired($now);
    }

    /**
     * The authorised Free fallback that follows an expired Pro record.
     *
     * It is the vendor's decision, not this side's: only a record the vendor
     * signed with `free_available` keeps anything after expiry, and only a Pro
     * record has something to fall back from. An expired Free record has no
     * lesser tier beneath it, so it simply ends. Nothing is synthesised — the
     * key, the dates and the authorised hosts remain the ones that were signed.
     */
    public function hasFreeFallback(\DateTimeImmutable $now): bool
    {
        if (!$this->freeAvailable || !$this->isMarkedValid()) {
            return false;
        }

        return CapabilityTier::fromPackage($this->package) === CapabilityTier::Pro && $this->isExpired($now);
    }

    /**
     * What this record entitles the installation to at the given instant.
     *
     * A record that has not started, is not marked valid, or has expired without
     * an authorised fallback entitles the installation to nothing, and the
     * product returns to behaving as it does with no licence at all.
     */
    public function tier(\DateTimeImmutable $now): CapabilityTier
    {
        if ($this->isEffective($now)) {
            return CapabilityTier::fromPackage($this->package);
        }

        return $this->hasFreeFallback($now) ? CapabilityTier::Free : CapabilityTier::None;
    }

    public function isConfirmationStale(\DateTimeImmutable $now, int $maxAgeSeconds = 86400): bool
    {
        return $this->verifiedAt > 0 && ($now->getTimestamp() - $this->verifiedAt) > $maxAgeSeconds;
    }

    /** A masked form of the key, safe for administrator display. */
    public function maskedKey(): string
    {
        $length = strlen($this->key);
        if ($length <= 8) {
            return str_repeat('•', $length);
        }

        return substr($this->key, 0, 4) . str_repeat('•', max(4, $length - 8)) . substr($this->key, -4);
    }

    /** Records why the document was refused and returns the refusal. */
    private static function reject(?string &$reason, string $label): null
    {
        $reason = $label;

        return null;
    }

    /**
     * @return list<string>|null
     */
    private static function readFeatureList(mixed $raw): ?array
    {
        if (!\is_array($raw) || !array_is_list($raw)) {
            return null;
        }
        $features = [];
        foreach ($raw as $feature) {
            if (!\is_string($feature) || $feature === '') {
                return null;
            }
            $features[] = $feature;
        }

        return $features;
    }

    /**
     * Reads the signed host set exactly as it was signed.
     *
     * The list is required to arrive already canonical — every entry a single
     * normalised host, no duplicates, sorted ascending. It is checked rather than
     * repaired: sorting or de-duplicating it here would mean verifying a signature
     * over one list and then using another, and a list that needs repair is a
     * disagreement between the two sides of the protocol, not a tidiness problem.
     *
     * @return list<string>|null
     */
    private static function readDomainSet(mixed $raw): ?array
    {
        if (!\is_array($raw) || !array_is_list($raw) || $raw === [] || \count($raw) > self::MAX_DOMAINS) {
            return null;
        }

        $domains = [];
        foreach ($raw as $entry) {
            // A wildcard never survives normalisation, so "*.example.com" is
            // refused here rather than becoming a scope.
            if (!\is_string($entry) || $entry === '' || HostIdentity::normalize($entry) !== $entry) {
                return null;
            }
            $domains[] = $entry;
        }

        if (\count(array_unique($domains)) !== \count($domains)) {
            return null;
        }
        $sorted = $domains;
        sort($sorted, \SORT_STRING);

        return $sorted === $domains ? $domains : null;
    }

    /** A protocol instant is a strictly positive integer number of seconds. */
    private static function readInstant(mixed $raw): ?int
    {
        return \is_int($raw) && $raw > 0 ? $raw : null;
    }
}
