<?php

namespace QaTracking\CaptureBundle\EventListener;

use QaTracking\CaptureBundle\Trace\TraceId;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Assigns a trace ID to each request and returns it to the browser in two ways:
 *
 * - Its own header (X-QA-Trace-Id). Across different origins it can only be
 *   read if it is listed in Access-Control-Expose-Headers, so it is added there
 *   when the response already carries CORS.
 * - Server-Timing, which the browser exposes through the Resource Timing API. It
 *   covers the requests the capture bar did not manage to intercept and the case where
 *   CORS is set by the web server rather than PHP.
 *
 * Like the whole bundle, it must never break the application: any failure is discarded.
 */
final class TraceListener
{
    public const ATTRIBUTE = '_qa_trace_id';
    public const SERVER_TIMING_METRIC = 'qa-trace';

    /** @param list<string> $allowedOrigins */
    public function __construct(
        private readonly bool $enabled,
        private readonly string $headerName,
        private readonly array $allowedOrigins,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        try {
            $event->getRequest()->attributes->set(self::ATTRIBUTE, TraceId::generate());
        } catch (\Throwable) {
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        try {
            $request = $event->getRequest();
            $traceId = $request->attributes->get(self::ATTRIBUTE);
            if (!TraceId::isValid($traceId)) {
                return;
            }

            $headers = $event->getResponse()->headers;
            $headers->set($this->headerName, $traceId);
            $headers->set('Server-Timing', \sprintf('%s;desc="%s"', self::SERVER_TIMING_METRIC, $traceId), false);

            if ($headers->has('Access-Control-Allow-Origin')) {
                $headers->set('Access-Control-Expose-Headers', $this->withHeader($headers->all('Access-Control-Expose-Headers'), $this->headerName));
            }

            $origin = $request->headers->get('Origin');
            if (null !== $origin && $this->isAllowedOrigin($origin)) {
                $headers->set('Timing-Allow-Origin', $this->withHeader($headers->all('Timing-Allow-Origin'), $origin));
                $headers->set('Vary', 'Origin', false);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Adds a value to a comma-separated list header, without duplicating it.
     *
     * @param list<string|null> $current
     */
    private function withHeader(array $current, string $value): string
    {
        $values = array_values(array_filter(array_map('trim', explode(',', implode(',', $current))), static fn (string $v) => '' !== $v));

        if (\in_array('*', $values, true)) {
            return '*';
        }
        if (!\in_array(strtolower($value), array_map('strtolower', $values), true)) {
            $values[] = $value;
        }

        return implode(', ', $values);
    }

    private function isAllowedOrigin(string $origin): bool
    {
        $origin = rtrim(strtolower(trim($origin)), '/');
        foreach ($this->allowedOrigins as $allowed) {
            if ($origin === rtrim(strtolower(trim($allowed)), '/')) {
                return true;
            }
        }

        return false;
    }
}
