<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\Registry;

use Vtinnovations\GuardianTypo3\Application\Contract\SessionEntryClaimInterface;
use Vtinnovations\GuardianTypo3\Domain\Environment\CapabilityGrant;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\Transport\PingTransportInterface;

/**
 * Tells the vendor, once per signed-in backend session, that an administrator
 * opened the protected module here.
 *
 * This is a different event from the per-invocation notice next to it and is
 * deliberately kept separate rather than merged into one broader packet. It
 * carries exactly two fields — the host and the activation key — and is sent from
 * the server to the vendor and nowhere else.
 *
 * The rules that keep that narrow:
 *
 *   - it is armed from the module's own entry point, not from reading entitlement,
 *     so it does not fire on an asset request, an AJAX poll, a console command, a
 *     queue worker or a frontend page;
 *   - the key comes only from a record that has just verified against the vendor's
 *     signature. An unverified or hand-edited file yields no key and no call. A
 *     record that is authentic but currently withheld — expired, or not for this
 *     domain — still yields one, because it is genuinely the key that was issued;
 *   - the host is the one the evaluation settled on, falling back to the host the
 *     record itself names, so it never depends on which URL a backend is open at;
 *   - the session claim is taken *before* delivery, so a timeout cannot turn into
 *     a second attempt later in the same session;
 *   - delivery is deferred to the end of the request, the answer is not read, and
 *     failure changes nothing about entitlement or what is rendered.
 *
 * The key never reaches the browser, a log, an exception, a diagnostic or the
 * session mark. It exists in memory for the length of one deferred POST.
 */
final class EntryNotice
{
    /**
     * @param bool $immediate test seam: deliver synchronously so a fake transport
     *                        can observe the call without a shutdown hook
     */
    public function __construct(
        private readonly PingTransportInterface $transport,
        private readonly ServiceEndpoint $endpoint,
        private readonly SessionEntryClaimInterface $claim,
        private readonly bool $immediate = false,
    ) {
    }

    /**
     * @param string $topic what is being claimed — the product slug for a package
     *                      whose entitlement covers the whole installation
     */
    public function arm(CapabilityGrant $grant, string $topic): void
    {
        $record = $grant->record;
        if ($record === null || $record->key === '') {
            return; // nothing authentic to announce
        }

        // The host the evaluation settled on; the record's own host when there was
        // no settled answer, which is still a signed, authenticated name.
        $domain = $grant->matchedDomain !== '' ? $grant->matchedDomain : $record->host;
        if ($domain === '') {
            return;
        }

        // Claimed before anything is sent: whether delivery succeeds is not
        // allowed to decide whether this session tries again.
        if (!$this->claim->claim($topic)) {
            return;
        }

        $body = json_encode(['domain' => $domain, 'key' => $record->key], \JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return;
        }
        $url = $this->endpoint->signal();

        if (!$this->immediate && \function_exists('register_shutdown_function')) {
            register_shutdown_function(function () use ($url, $body): void {
                $this->deliver($url, $body);
            });

            return;
        }
        $this->deliver($url, $body);
    }

    private function deliver(string $url, string $body): void
    {
        try {
            $this->transport->send($url, $body);
        } catch (\Throwable) {
            // silent and non-blocking by design
        }
    }
}
