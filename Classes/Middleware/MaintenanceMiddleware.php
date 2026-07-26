<?php

declare(strict_types=1);

/*
 * This file is part of the "Guardian for TYPO3" extension for TYPO3 CMS.
 *
 * @author    V&T Innovations Team
 * @license   LGPL-3.0-or-later
 * @copyright V&T Innovations 2026 - 2028
 */

namespace Vtinnovations\GuardianTypo3\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\HtmlResponse;

/**
 * Serves a 503 "maintenance" page to frontend visitors while Guardian's
 * maintenance marker exists (var/guardian/maintenance.lock), which recovery sets
 * for the duration of a restore. Registered only in the frontend middleware
 * stack, so the backend and CLI are never affected.
 *
 * It reads the marker via {@see Environment} (no DI dependencies) so it stays
 * cheap and works even mid-request during a recovery.
 */
final class MaintenanceMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->maintenanceActive()) {
            return $handler->handle($request);
        }

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="robots" content="noindex"><title>Maintenance</title>'
            . '<style>body{font-family:system-ui,sans-serif;background:#111;color:#eee;display:flex;'
            . 'min-height:100vh;align-items:center;justify-content:center;margin:0}'
            . 'main{max-width:32rem;text-align:center;padding:2rem}h1{color:#f47c00}</style></head>'
            . '<body><main><h1>Maintenance</h1><p>The site is temporarily offline while a backup is being restored. '
            . 'Please try again shortly.</p></main></body></html>';

        return new HtmlResponse($html, 503, ['Retry-After' => '120']);
    }

    private function maintenanceActive(): bool
    {
        $marker = rtrim(Environment::getVarPath(), '/') . '/guardian/maintenance.lock';

        return is_file($marker);
    }
}
