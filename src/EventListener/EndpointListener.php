<?php

namespace QaTracking\CaptureBundle\EventListener;

use QaTracking\CaptureBundle\Context\ContextAssembler;
use QaTracking\CaptureBundle\Security\ClientFingerprint;
use QaTracking\CaptureBundle\Storage\ContextStore;
use QaTracking\CaptureBundle\Tracker\TrackerClient;
use QaTracking\CaptureBundle\Tracker\TrackerException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Handles `POST {prefix}/context`: the capture bar sends the trace IDs it saw and the
 * bundle sends the context of those requests to the tracker.
 *
 * It is an early listener and not a route so that installing the bundle does not
 * require touching the application's firewall or CORS configuration. In exchange,
 * it brings its own controls:
 *
 * - it only responds to the configured origins;
 * - each trace ID is unpredictable, expires, and can only be used by the browser
 *   that made the original request;
 * - it never returns data to the browser: data goes from the server to the tracker,
 *   and here only a token and counts are returned.
 */
final class EndpointListener
{
    private const RATE_LIMIT = 30;
    private const RATE_WINDOW = 60;
    private const MAX_BODY_BYTES = 16384;

    /** @param list<string> $allowedOrigins */
    public function __construct(
        private readonly ContextAssembler $assembler,
        private readonly TrackerClient $tracker,
        private readonly ContextStore $store,
        private readonly bool $enabled,
        private readonly string $pathPrefix,
        private readonly array $allowedOrigins,
        private readonly bool $bindToClient,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (rtrim($this->pathPrefix, '/').'/context' !== $request->getPathInfo()) {
            return;
        }

        try {
            $response = $this->handle($request);
        } catch (\Throwable) {
            $response = new JsonResponse(['error' => 'internal_error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $origin = $this->allowedOrigin($request);
        if (null !== $origin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        }
        $response->headers->set('Cache-Control', 'no-store');

        // Setting the response here stops the event: the firewall and the router never run.
        $event->setResponse($response);
    }

    private function handle(Request $request): Response
    {
        if (null === $this->allowedOrigin($request)) {
            return new JsonResponse(['error' => 'origin_not_allowed'], Response::HTTP_FORBIDDEN);
        }

        if ($request->isMethod('OPTIONS')) {
            return new Response('', Response::HTTP_NO_CONTENT, [
                'Access-Control-Allow-Methods' => 'POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type',
                'Access-Control-Max-Age' => '600',
            ]);
        }
        if (!$request->isMethod('POST')) {
            return new JsonResponse(['error' => 'method_not_allowed'], Response::HTTP_METHOD_NOT_ALLOWED, ['Allow' => 'POST, OPTIONS']);
        }

        $fingerprint = ClientFingerprint::of($request);
        if (!$this->store->allow($fingerprint, self::RATE_LIMIT, self::RATE_WINDOW)) {
            return new JsonResponse(['error' => 'too_many_requests'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $body = $request->getContent();
        $data = \strlen($body) <= self::MAX_BODY_BYTES ? json_decode($body, true) : null;
        if (!\is_array($data) || !\is_array($data['traceIds'] ?? null)) {
            return new JsonResponse(['error' => 'invalid_body'], Response::HTTP_BAD_REQUEST);
        }

        $assembled = $this->assembler->assemble($data['traceIds'], $this->bindToClient ? $fingerprint : null);
        if ([] === $assembled['payload']['contexts']) {
            return new JsonResponse(['contextToken' => null, 'summary' => $assembled['summary']]);
        }

        try {
            $token = $this->tracker->pushContext($assembled['payload']);
        } catch (TrackerException $e) {
            return new JsonResponse(['error' => 'tracker_unavailable', 'message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['contextToken' => $token, 'summary' => $assembled['summary']]);
    }

    private function allowedOrigin(Request $request): ?string
    {
        $origin = $request->headers->get('Origin');
        if (null === $origin) {
            return null;
        }

        $normalized = rtrim(strtolower(trim($origin)), '/');
        foreach ($this->allowedOrigins as $allowed) {
            if ($normalized === rtrim(strtolower(trim($allowed)), '/')) {
                return $origin;
            }
        }

        return null;
    }
}
