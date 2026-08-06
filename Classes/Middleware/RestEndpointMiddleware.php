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
use TYPO3\CMS\Core\Http\JsonResponse;
use Vtinnovations\GuardianTypo3\Application\Configuration\RecordIntake;
use Vtinnovations\GuardianTypo3\Domain\Clock\ClockInterface;
use Vtinnovations\GuardianTypo3\Domain\Configuration\RecordIntakeOutcome;
use Vtinnovations\GuardianTypo3\Infrastructure\Registry\ServiceEndpoint;
use Vtinnovations\GuardianTypo3\Typo3\Authorization\SignedRequestAuthorization;

/**
 * Guardian's one public machine-facing route.
 *
 * It runs in the frontend stack before site and page resolution, so the path is
 * reachable regardless of the page tree, the site configuration and page slugs,
 * and cannot be shadowed by a frontend 404. Exactly one path is claimed; every
 * other request — pages, assets, the backend, the install tool, the standalone
 * recovery file and any unrelated `/rest/` URL — passes straight through.
 *
 * The handler stays deliberately thin. It enforces the shape of the request —
 * the verb, the media type, a hard ceiling on the body before anything parses it
 * — and then hands over. It holds no key, computes no digest, makes no decision
 * about entitlement and writes nothing to disk.
 *
 * A backend route token is intentionally not required: that mechanism protects
 * interactive browser sessions, and this caller is a server. Authentication is
 * cryptographic instead, and happens before the body is interpreted as anything
 * but bytes. Answers are minimal, and identical for every kind of refusal, so the
 * endpoint cannot be used to probe which check failed.
 */
final class RestEndpointMiddleware implements MiddlewareInterface
{
    /** A record push is small; anything larger is not one. */
    private const MAX_BODY_BYTES = 65536;

    public function __construct(
        private readonly SignedRequestAuthorization $authorization,
        private readonly RecordIntake $intake,
        private readonly ServiceEndpoint $endpoint,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $this->endpoint->inboundPath();
        if (!$this->claims($request->getUri()->getPath(), $path)) {
            return $handler->handle($request);
        }

        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->answer(RecordIntakeOutcome::methodNotAllowed())->withHeader('Allow', 'POST');
        }

        $mediaType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
        if ($mediaType !== ServiceEndpoint::MEDIA_TYPE) {
            return $this->answer(RecordIntakeOutcome::unsupportedMediaType());
        }

        $declared = $request->getHeaderLine('Content-Length');
        if ($declared !== '' && (int) $declared > self::MAX_BODY_BYTES) {
            return $this->answer(RecordIntakeOutcome::payloadTooLarge());
        }

        // Read one byte beyond the ceiling so an undeclared oversize body is
        // still caught, without ever holding more than the ceiling plus one.
        $raw = $request->getBody()->read(self::MAX_BODY_BYTES + 1);
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return $this->answer(RecordIntakeOutcome::payloadTooLarge());
        }

        $body = json_decode($raw, true);
        if (!\is_array($body) || array_is_list($body)) {
            return $this->answer(RecordIntakeOutcome::malformed());
        }

        $now = $this->clock->now()->getTimestamp();
        $identity = $this->authorization->authenticate($request, $raw, $body, $path, $now);
        $outcome = $this->intake->accept($identity, $body, $raw, $now);

        return $this->answer($outcome);
    }

    /** The exact path, optionally with one trailing slash. Never a subpath. */
    private function claims(string $requestPath, string $endpointPath): bool
    {
        return $requestPath === $endpointPath || $requestPath === $endpointPath . '/';
    }

    /**
     * The protocol's answer shape. A success states what happened and to which
     * version; a refusal says only that it was refused.
     */
    private function answer(RecordIntakeOutcome $outcome): ResponseInterface
    {
        if (!$outcome->isSuccess()) {
            return new JsonResponse(['status' => 'error'], $outcome->httpStatus);
        }

        $payload = ['status' => $outcome->status, 'request_id' => $outcome->requestId];
        if ($outcome->version !== null) {
            $payload['license_version'] = $outcome->version;
        }

        return new JsonResponse($payload, $outcome->httpStatus);
    }
}
