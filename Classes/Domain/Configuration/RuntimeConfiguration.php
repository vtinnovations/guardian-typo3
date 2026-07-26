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

/**
 * Immutable runtime configuration for Guardian.
 *
 * Mirrors the small set of operator-tunable values the Contao bundle stored in
 * var/updater/runtime.json — most importantly the explicit PHP CLI binary path,
 * which is essential on managed hosts (Plesk/cPanel) where the web SAPI and the
 * CLI binary differ. Redesigned here as a validated, immutable value object with
 * no dependency on the CMS, the filesystem, or superglobals: construction always
 * yields a normalised, self-consistent object or throws.
 *
 * Persistence is the concern of a repository in the infrastructure layer; this
 * object only knows the rules for what a valid configuration looks like.
 */
final class RuntimeConfiguration
{
    /**
     * Recovery-panel filename rule (kept identical to the audited Contao rule):
     * a single path segment of [A-Za-z0-9._-], 1-60 chars, ending in ".php",
     * never containing "..". The panel itself is a later, security-critical
     * deliverable; the constraint is validated here so the value can be stored
     * and displayed safely in the meantime.
     */
    private const PANEL_FILENAME_PATTERN = '#^[A-Za-z0-9._-]{1,60}\.php$#';

    public const DEFAULT_PANEL_FILENAME = '_guardian-recovery.php';

    private function __construct(
        public readonly string $phpBinary,
        public readonly string $composerPhar,
        public readonly string $recoveryEmail,
        public readonly string $notificationSenderEmail,
        public readonly string $recoveryPanelFilename,
        public readonly bool $recoveryNotificationsEnabled = false,
    ) {
    }

    /**
     * The safe, empty-but-valid default configuration.
     */
    public static function createDefault(): self
    {
        return new self(
            phpBinary: '',
            composerPhar: '',
            recoveryEmail: '',
            notificationSenderEmail: '',
            recoveryPanelFilename: self::DEFAULT_PANEL_FILENAME,
            recoveryNotificationsEnabled: false,
        );
    }

    /**
     * Builds a configuration from an arbitrary (e.g. JSON-decoded) array,
     * normalising every field. Invalid-but-recoverable values fall back to a
     * safe default rather than throwing — this matches how operators expect a
     * settings screen to behave (a bad e-mail address is dropped, not fatal).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $default = self::createDefault();

        return new self(
            phpBinary: self::cleanPath($data['php_binary'] ?? ''),
            composerPhar: self::cleanPath($data['composer_phar'] ?? ''),
            recoveryEmail: self::cleanRecipients($data['recovery_email'] ?? ''),
            notificationSenderEmail: self::cleanEmail($data['notification_sender_email'] ?? ''),
            recoveryPanelFilename: self::cleanPanelFilename(
                $data['recovery_panel_filename'] ?? '',
                $default->recoveryPanelFilename
            ),
            recoveryNotificationsEnabled: self::cleanBool($data['recovery_notifications_enabled'] ?? false),
        );
    }

    /**
     * @return array<string, string|bool>
     */
    public function toArray(): array
    {
        return [
            'php_binary' => $this->phpBinary,
            'composer_phar' => $this->composerPhar,
            'recovery_email' => $this->recoveryEmail,
            'notification_sender_email' => $this->notificationSenderEmail,
            'recovery_panel_filename' => $this->recoveryPanelFilename,
            'recovery_notifications_enabled' => $this->recoveryNotificationsEnabled,
        ];
    }

    public function withPhpBinary(string $phpBinary): self
    {
        return new self(
            self::cleanPath($phpBinary),
            $this->composerPhar,
            $this->recoveryEmail,
            $this->notificationSenderEmail,
            $this->recoveryPanelFilename,
            $this->recoveryNotificationsEnabled,
        );
    }

    /**
     * Returns a copy with the recovery-notification settings replaced (recipients
     * may be a comma/semicolon/whitespace-separated list; invalid ones are dropped).
     */
    public function withNotifications(bool $enabled, string $recipients, string $sender): self
    {
        return new self(
            $this->phpBinary,
            $this->composerPhar,
            self::cleanRecipients($recipients),
            self::cleanEmail($sender),
            $this->recoveryPanelFilename,
            $enabled,
        );
    }

    public function hasPhpBinary(): bool
    {
        return $this->phpBinary !== '';
    }

    public function hasRecoveryEmail(): bool
    {
        return $this->recoveryEmail !== '';
    }

    /**
     * The normalised, validated recipient list.
     *
     * @return list<string>
     */
    public function recoveryRecipients(): array
    {
        if ($this->recoveryEmail === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $this->recoveryEmail))));
    }

    /**
     * Notifications are actually sent only when enabled AND at least one valid
     * recipient is configured.
     */
    public function notificationsActive(): bool
    {
        return $this->recoveryNotificationsEnabled && $this->recoveryEmail !== '';
    }

    private static function cleanPath(mixed $value): string
    {
        return trim((string) $value);
    }

    private static function cleanBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private static function cleanEmail(mixed $value): string
    {
        $email = trim((string) $value);
        if ($email === '' || filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            return '';
        }

        return $email;
    }

    /**
     * Normalise a recipient string (single address OR a comma/semicolon/space
     * separated list) into a de-duplicated, comma-joined list of valid addresses.
     * Invalid addresses are dropped, never fatal.
     */
    private static function cleanRecipients(mixed $value): string
    {
        $parts = preg_split('/[\s,;]+/', trim((string) $value)) ?: [];
        $valid = [];
        foreach ($parts as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, \FILTER_VALIDATE_EMAIL) !== false) {
                $valid[strtolower($candidate)] = $candidate;
            }
        }

        return implode(', ', array_values($valid));
    }

    private static function cleanPanelFilename(mixed $value, string $fallback): string
    {
        $name = trim((string) $value);
        if ($name === '' || str_contains($name, '..') || preg_match(self::PANEL_FILENAME_PATTERN, $name) !== 1) {
            return $fallback;
        }

        return $name;
    }
}
