<?php

namespace QaTracking\CaptureBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use QaTracking\CaptureBundle\Tests\TestKernel;
use QaTracking\CaptureBundle\Trace\TraceId;

final class TraceHeaderTest extends TestCase
{
    private const ENABLED = ['enabled' => true, 'allowed_origins' => [TestKernel::CORS_ORIGIN]];

    public function testEveryResponseCarriesADifferentTraceId(): void
    {
        $kernel = new TestKernel(self::ENABLED);

        $first = $kernel->get('/ok')->headers->get('X-QA-Trace-Id');
        $second = $kernel->get('/ok')->headers->get('X-QA-Trace-Id');

        self::assertTrue(TraceId::isValid($first));
        self::assertTrue(TraceId::isValid($second));
        self::assertNotSame($first, $second);
    }

    public function testErrorResponsesAlsoCarryTheTraceId(): void
    {
        $response = (new TestKernel(self::ENABLED))->get('/does-not-exist');

        self::assertSame(404, $response->getStatusCode());
        self::assertTrue(TraceId::isValid($response->headers->get('X-QA-Trace-Id')));
    }

    public function testNothingIsAddedWhenDisabled(): void
    {
        $response = (new TestKernel(['enabled' => false]))->get('/ok', ['Origin' => TestKernel::CORS_ORIGIN]);

        self::assertFalse($response->headers->has('X-QA-Trace-Id'));
        self::assertFalse($response->headers->has('Server-Timing'));
        self::assertSame('Link', $response->headers->get('Access-Control-Expose-Headers'));
    }

    public function testTheHeaderIsExposedWhenTheResponseHasCors(): void
    {
        $response = (new TestKernel(self::ENABLED))->get('/ok', ['Origin' => TestKernel::CORS_ORIGIN]);

        self::assertSame('Link, X-QA-Trace-Id', $response->headers->get('Access-Control-Expose-Headers'));
    }

    public function testCorsHeadersAreNotInventedForSameOriginRequests(): void
    {
        $response = (new TestKernel(self::ENABLED))->get('/ok');

        self::assertFalse($response->headers->has('Access-Control-Expose-Headers'));
        self::assertFalse($response->headers->has('Timing-Allow-Origin'));
    }

    public function testTheTraceIdAlsoTravelsInServerTimingForAllowedOrigins(): void
    {
        $response = (new TestKernel(self::ENABLED))->get('/ok', ['Origin' => TestKernel::CORS_ORIGIN]);
        $traceId = $response->headers->get('X-QA-Trace-Id');

        self::assertSame('qa-trace;desc="'.$traceId.'"', $response->headers->get('Server-Timing'));
        self::assertSame(TestKernel::CORS_ORIGIN, $response->headers->get('Timing-Allow-Origin'));
    }

    public function testResourceTimingIsNotOpenedToUnknownOrigins(): void
    {
        $response = (new TestKernel(self::ENABLED))->get('/ok', ['Origin' => 'https://other-site.example.com']);

        self::assertFalse($response->headers->has('Timing-Allow-Origin'));
    }

    public function testTheHeaderNameIsConfigurable(): void
    {
        $response = (new TestKernel(['enabled' => true, 'trace_header' => 'X-Trace']))->get('/ok');

        self::assertTrue(TraceId::isValid($response->headers->get('X-Trace')));
        self::assertFalse($response->headers->has('X-QA-Trace-Id'));
    }

    public function testRefusesToLoadInProdUnlessExplicitlyAllowed(): void
    {
        $response = (new TestKernel(['enabled' => true, 'allow_prod_env' => true], 'prod'))->get('/ok');
        self::assertTrue($response->headers->has('X-QA-Trace-Id'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('allow_prod_env');
        (new TestKernel(['enabled' => true], 'prod'))->get('/ok');
    }
}
