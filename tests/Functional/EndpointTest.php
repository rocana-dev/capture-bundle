<?php

namespace QaTracking\CaptureBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use QaTracking\CaptureBundle\Tests\TestKernel;
use Symfony\Component\HttpFoundation\Response;

final class EndpointTest extends TestCase
{
    private const CONFIG = [
        'enabled' => true,
        'allowed_origins' => [TestKernel::CORS_ORIGIN],
        'tracker_url' => 'https://tracker.example.com/',
        'project_secret' => TestKernel::SECRET,
    ];
    private const ORIGIN = ['Origin' => TestKernel::CORS_ORIGIN];

    protected function setUp(): void
    {
        TestKernel::resetTracker();
    }

    /** @param list<string> $traceIds */
    private function askForContext(TestKernel $kernel, array $traceIds, array $headers = self::ORIGIN): Response
    {
        return $kernel->request('POST', '/_qa/context', ['Content-Type' => 'application/json', ...$headers], json_encode(['traceIds' => $traceIds]));
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 16, \JSON_THROW_ON_ERROR);
    }

    public function testSendsTheContextsToTheTrackerAndAnswersOnlyATokenAndCounts(): void
    {
        $kernel = new TestKernel(self::CONFIG);
        $boom = $kernel->get('/boom', self::ORIGIN)->headers->get('X-QA-Trace-Id');
        $log = $kernel->get('/log', self::ORIGIN)->headers->get('X-QA-Trace-Id');

        $response = $this->askForContext($kernel, [$log, $boom, 'not-a-trace-id']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(TestKernel::CORS_ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));
        $body = $this->json($response);
        self::assertSame('ctx_test_token', $body['contextToken']);
        self::assertSame(2, $body['summary']['requests']);
        self::assertSame(1, $body['summary']['errors']);
        self::assertGreaterThan(0, $body['summary']['logs']);
        self::assertSame(['contextToken', 'summary'], array_keys($body), 'The browser must not receive the context.');

        self::assertCount(1, TestKernel::$trackerRequests);
        $sent = TestKernel::$trackerRequests[0];
        self::assertSame('POST', $sent['method']);
        self::assertSame('https://tracker.example.com/api/ingest/backend-context', $sent['url']);
        self::assertContains('Authorization: Bearer '.TestKernel::SECRET, $sent['headers']);
        self::assertSame(['/boom', '/log'], array_map(static fn (array $c) => $c['request']['path'], $sent['body']['contexts']));
        self::assertArrayNotHasKey('client', $sent['body']['contexts'][0]);
        self::assertSame(\RuntimeException::class, $sent['body']['contexts'][0]['exception']['class']);
    }

    public function testAnotherBrowserCannotUseSomeoneElsesTraceId(): void
    {
        $kernel = new TestKernel(self::CONFIG);
        $traceId = $kernel->get('/log', self::ORIGIN)->headers->get('X-QA-Trace-Id');

        $response = $this->askForContext($kernel, [$traceId], [...self::ORIGIN, 'User-Agent' => 'Another browser']);

        self::assertNull($this->json($response)['contextToken']);
        self::assertSame([], TestKernel::$trackerRequests);
    }

    public function testRejectsOriginsThatAreNotConfigured(): void
    {
        $kernel = new TestKernel(self::CONFIG);
        $traceId = $kernel->get('/log')->headers->get('X-QA-Trace-Id');

        foreach ([['Origin' => 'https://other-site.example.com'], []] as $headers) {
            $response = $this->askForContext($kernel, [$traceId], $headers);

            self::assertSame(403, $response->getStatusCode());
            self::assertFalse($response->headers->has('Access-Control-Allow-Origin'));
        }
        self::assertSame([], TestKernel::$trackerRequests);
    }

    public function testAnswersThePreflightRequest(): void
    {
        $response = (new TestKernel(self::CONFIG))->request('OPTIONS', '/_qa/context', [...self::ORIGIN, 'Access-Control-Request-Method' => 'POST']);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(TestKernel::CORS_ORIGIN, $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('Content-Type', (string) $response->headers->get('Access-Control-Allow-Headers'));
    }

    public function testATrackerFailureIsReportedAsBadGateway(): void
    {
        TestKernel::$trackerResponse = [401, ['error' => 'Invalid secret key.']];
        $kernel = new TestKernel(self::CONFIG);
        $traceId = $kernel->get('/log', self::ORIGIN)->headers->get('X-QA-Trace-Id');

        $response = $this->askForContext($kernel, [$traceId]);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('tracker_unavailable', $this->json($response)['error']);
    }

    public function testAMissingTrackerConfigurationIsReportedToo(): void
    {
        $kernel = new TestKernel(['enabled' => true, 'allowed_origins' => [TestKernel::CORS_ORIGIN]]);
        $traceId = $kernel->get('/log', self::ORIGIN)->headers->get('X-QA-Trace-Id');

        self::assertSame(502, $this->askForContext($kernel, [$traceId])->getStatusCode());
    }

    public function testRejectsABodyWithoutTraceIds(): void
    {
        $response = (new TestKernel(self::CONFIG))->request('POST', '/_qa/context', self::ORIGIN, '{"something":"else"}');

        self::assertSame(400, $response->getStatusCode());
    }

    public function testUnknownTraceIdsProduceNoToken(): void
    {
        $response = $this->askForContext(new TestKernel(self::CONFIG), [str_repeat('a', 32)]);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->json($response)['contextToken']);
        self::assertSame(0, $this->json($response)['summary']['requests']);
    }

    public function testTheEndpointItselfIsNotTraced(): void
    {
        $response = $this->askForContext(new TestKernel(self::CONFIG), []);

        self::assertFalse($response->headers->has('X-QA-Trace-Id'));
    }

    public function testTheEndpointDoesNotExistWhenDisabled(): void
    {
        $response = $this->askForContext(new TestKernel([...self::CONFIG, 'enabled' => false]), []);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testThePathPrefixIsConfigurable(): void
    {
        $kernel = new TestKernel([...self::CONFIG, 'path_prefix' => '/api/_qa']);

        self::assertSame(200, $kernel->request('POST', '/api/_qa/context', self::ORIGIN, '{"traceIds":[]}')->getStatusCode());
        self::assertSame(404, $kernel->request('POST', '/_qa/context', self::ORIGIN, '{"traceIds":[]}')->getStatusCode());
    }
}
