<?php

namespace QaTracking\CaptureBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use QaTracking\CaptureBundle\Storage\ContextStore;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ContextStoreTest extends TestCase
{
    private const TRACE = '0123456789abcdef0123456789abcdef';

    public function testHandsTheContextOnlyToTheBrowserThatMadeTheRequest(): void
    {
        $store = new ContextStore(new ArrayAdapter(), 60, 5);
        $store->save(['traceId' => self::TRACE, 'client' => 'fingerprint-a', 'request' => ['path' => '/x']]);

        self::assertNull($store->claim(self::TRACE, 'fingerprint-b'));
        self::assertSame(['traceId' => self::TRACE, 'request' => ['path' => '/x']], $store->claim(self::TRACE, 'fingerprint-a'));
    }

    public function testTheFingerprintCheckCanBeTurnedOff(): void
    {
        $store = new ContextStore(new ArrayAdapter(), 60, 5);
        $store->save(['traceId' => self::TRACE, 'client' => 'fingerprint-a']);

        self::assertNotNull($store->claim(self::TRACE, null));
    }

    public function testAContextCanOnlyBeClaimedALimitedNumberOfTimes(): void
    {
        $store = new ContextStore(new ArrayAdapter(), 60, 2);
        $store->save(['traceId' => self::TRACE, 'client' => 'a']);

        self::assertNotNull($store->claim(self::TRACE, 'a'));
        self::assertNotNull($store->claim(self::TRACE, 'a'));
        self::assertNull($store->claim(self::TRACE, 'a'));
    }

    public function testSavingAgainKeepsTheClaimCount(): void
    {
        $store = new ContextStore(new ArrayAdapter(), 60, 1);
        $store->save(['traceId' => self::TRACE, 'client' => 'a']);
        $store->claim(self::TRACE, 'a');

        $store->save(['traceId' => self::TRACE, 'client' => 'a', 'exception' => ['class' => 'X']]);

        self::assertNull($store->claim(self::TRACE, 'a'));
    }

    public function testUnknownTraceIdsYieldNothing(): void
    {
        self::assertNull((new ContextStore(new ArrayAdapter(), 60, 5))->claim(self::TRACE, 'a'));
    }

    public function testRateLimitAllowsUpToTheLimitPerWindow(): void
    {
        $store = new ContextStore(new ArrayAdapter(), 60, 5);

        self::assertTrue($store->allow('client', 2, 3600));
        self::assertTrue($store->allow('client', 2, 3600));
        self::assertFalse($store->allow('client', 2, 3600));
        self::assertTrue($store->allow('other', 2, 3600));
    }
}
