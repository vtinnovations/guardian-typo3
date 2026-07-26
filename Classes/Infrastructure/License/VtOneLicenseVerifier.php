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
use Vtinnovations\GuardianTypo3\Application\Contract\LicenseVerifierInterface;
use Vtinnovations\GuardianTypo3\Domain\License\LicenseVerificationResult;

/**
 * Confirms a license against the V-T.ONE verification endpoint
 * (https://www.v-t.one/api/v1/verify) and returns the full set of server-supplied
 * license facts (dates, lifetime flag, package, features, document version,
 * signature) via the shared {@see LicenseResponseParser}. This is the periodic
 * "is this key still valid?" check; the authoritative document refresh used by
 * the "Update License" action is {@see VtOneLicenseUpdater}.
 */
final class VtOneLicenseVerifier implements LicenseVerifierInterface
{
    private const ENDPOINT = 'https://www.v-t.one/api/v1/verify';
    private const PRODUCT = 'vt-guardian';
    private const API_HEADER = 'X-VT-Api-Key';
    private const API_CREDENTIAL = 'X-VT-API';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LicenseResponseParser $parser = new LicenseResponseParser(),
    ) {
    }

    public function verify(string $licenseKey, string $domain, string $requiredPackage = ''): LicenseVerificationResult
    {
        $payload = ['key' => $licenseKey, 'domain' => $domain, 'product' => self::PRODUCT];
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
                return LicenseVerificationResult::unreachable('License server temporarily unavailable.');
            }

            return $this->parser->parse(json_decode((string) $response->getBody(), true));
        } catch (\Throwable) {
            return LicenseVerificationResult::unreachable('Could not connect to the license server.');
        }
    }
}
