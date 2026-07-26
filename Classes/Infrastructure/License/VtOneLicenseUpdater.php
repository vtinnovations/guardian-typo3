<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Infrastructure\License;

use TYPO3\CMS\Core\Http\RequestFactory;
use Vtinnovations\GuardianTypo3\Application\Contract\LicenseUpdaterInterface;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseVerificationResult;

/**
 * Fetches the authoritative, complete license document from the V-T.ONE
 * license-updater endpoint (https://www.v-t.one/rest/api/v1/guardian-license-updater),
 * used by the "Update License" administrator action. The response is decoded by
 * the shared {@see LicenseResponseParser}, so it yields the same rich, dated
 * result the verify endpoint does — including the detached signature the store
 * persists for the optional signature layer.
 *
 * A 5xx / connection failure returns an "unreachable" result so the manager
 * preserves a previously valid stored license rather than overwriting it.
 */
final class VtOneLicenseUpdater implements LicenseUpdaterInterface
{
    private const ENDPOINT = 'https://www.v-t.one/rest/api/v1/guardian-license-updater';
    private const PRODUCT = 'vt-guardian';
    private const PROJECT_SLUG = 'guardian';
    private const API_HEADER = 'X-VT-Api-Key';
    private const API_CREDENTIAL = 'X-VT-API';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LicenseResponseParser $parser = new LicenseResponseParser(),
    ) {
    }

    public function update(string $licenseKey, string $domain, string $requiredPackage = ''): LicenseVerificationResult
    {
        $payload = [
            'key' => $licenseKey,
            'domain' => $domain,
            'product' => self::PRODUCT,
            'project_slug' => self::PROJECT_SLUG,
        ];
        if ($requiredPackage !== '') {
            $payload['require_package'] = $requiredPackage;
        }

        try {
            $response = $this->requestFactory->request(self::ENDPOINT, 'POST', [
                'headers' => [self::API_HEADER => self::API_CREDENTIAL, 'Accept' => 'application/json'],
                'json' => $payload,
                'connect_timeout' => 5.0,
                'timeout' => 5.0,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
            $status = $response->getStatusCode();
            if ($status >= 500 || $status === 0) {
                return LicenseVerificationResult::unreachable('License updater temporarily unavailable.');
            }

            return $this->parser->parse(json_decode((string) $response->getBody(), true));
        } catch (\Throwable) {
            return LicenseVerificationResult::unreachable('Could not connect to the license updater.');
        }
    }
}
