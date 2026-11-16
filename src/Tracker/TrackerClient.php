<?php

namespace QaTracking\CaptureBundle\Tracker;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends the backend context to the tracker, server to server. The project's
 * secret key never reaches the browser.
 */
final class TrackerClient
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ?string $trackerUrl,
        private readonly ?string $projectSecret,
        private readonly float $timeout = 5.0,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim((string) $this->trackerUrl) && '' !== trim((string) $this->projectSecret);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return string token that identifies the context stored in the tracker
     */
    public function pushContext(array $payload): string
    {
        if (!$this->isConfigured()) {
            throw new TrackerException('"tracker_url" and "project_secret" are missing from the qa_capture configuration.');
        }

        try {
            $response = $this->http->request('POST', rtrim((string) $this->trackerUrl, '/').'/api/ingest/backend-context', [
                'auth_bearer' => $this->projectSecret,
                'json' => $payload,
                'timeout' => $this->timeout,
                'max_duration' => $this->timeout * 2,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new TrackerException('Could not reach the tracker: '.$e->getMessage(), 0, $e);
        }

        if ($status >= 300 || !\is_string($data['contextToken'] ?? null)) {
            throw new TrackerException(\sprintf('The tracker answered %d: %s', $status, \is_string($data['error'] ?? null) ? $data['error'] : 'no details'));
        }

        return $data['contextToken'];
    }
}
