<?php

namespace QaTracking\CaptureBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use QaTracking\CaptureBundle\Tests\TestKernel;

final class ContextCaptureTest extends TestCase
{
    private const ENABLED = ['enabled' => true, 'allowed_origins' => [TestKernel::CORS_ORIGIN]];

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>|null
     */
    private function contextOf(string $path, array $config = self::ENABLED, ?TestKernel $kernel = null): ?array
    {
        $kernel ??= new TestKernel($config);
        $traceId = $kernel->get($path)->headers->get('X-QA-Trace-Id');

        return null === $traceId ? null : $kernel->getContainer()->get('test.store')->claim($traceId, TestKernel::fingerprint());
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function sqlRecord(array $context): array
    {
        $records = array_values(array_filter($context['logs'], static fn (array $log) => 'doctrine' === $log['channel']));
        self::assertCount(1, $records);

        return $records[0];
    }

    public function testStoresWhatTheRequestWasAndHowItEnded(): void
    {
        $context = $this->contextOf('/log?page=2&token=abc123');

        self::assertSame('GET', $context['request']['method']);
        self::assertSame('/log', $context['request']['path']);
        self::assertSame('page=2&token=%2A%2A%2A', $context['request']['query']);
        self::assertSame('log', $context['request']['route']);
        self::assertSame('test.controller::log', $context['request']['controller']);
        self::assertSame(200, $context['response']['status']);
        self::assertIsInt($context['response']['durationMs']);
        self::assertNull($context['exception']);
        self::assertEqualsWithDelta(microtime(true) * 1000, $context['startedAt'], 5000);
    }

    public function testCapturesLogRecordsWithTheirContextMasked(): void
    {
        $context = $this->contextOf('/log');
        $messages = array_column($context['logs'], 'message');

        self::assertContains('Invoice F001-0023 viewed', $messages);
        self::assertContains('Debug detail', $messages);

        $record = $context['logs'][array_search('Invoice F001-0023 viewed', $messages, true)];
        self::assertSame('INFO', $record['level']);
        self::assertSame('app', $record['channel']);
        self::assertSame('***', $record['context']['password']);
        self::assertSame(['api_token' => '***', 'name' => 'Ana'], $record['context']['user']);
        self::assertEqualsWithDelta($context['startedAt'], $record['at'], 5000);
    }

    public function testExcludedChannelsAreNotCaptured(): void
    {
        $context = $this->contextOf('/log', [...self::ENABLED, 'logs' => ['exclude_channels' => ['app']]]);

        self::assertNotContains('Invoice F001-0023 viewed', array_column($context['logs'], 'message'));
    }

    public function testSqlQueriesAreKeptWithoutTheirParametersByDefault(): void
    {
        $record = $this->sqlRecord($this->contextOf('/sql'));

        self::assertSame(['sql' => 'SELECT * FROM customer WHERE email = ?'], $record['context']);
        self::assertStringNotContainsString('ana@example.com', json_encode($record));
    }

    public function testSqlParametersCanBeIncludedOnRequest(): void
    {
        $record = $this->sqlRecord($this->contextOf('/sql', [...self::ENABLED, 'logs' => ['sql_params' => true]]));

        self::assertSame([1 => 'ana@example.com'], $record['context']['params']);
    }

    public function testCapturesTheExceptionWithItsCause(): void
    {
        $context = $this->contextOf('/boom');
        $exception = $context['exception'];

        self::assertSame(500, $context['response']['status']);
        self::assertSame(\RuntimeException::class, $exception['class']);
        self::assertSame('Could not generate the PDF', $exception['message']);
        self::assertSame(42, $exception['code']);
        self::assertSame('tests/Fixtures/DemoController.php', $exception['file']);
        self::assertNotEmpty($exception['trace']);
        self::assertArrayNotHasKey('args', $exception['trace'][0]);
        self::assertSame(\LogicException::class, $exception['previous'][0]['class']);
        self::assertContains('The template "invoice_v2" does not exist', array_column($context['logs'], 'message'));
    }

    public function testAFailedRequestIsStoredEvenIfItNeverTerminates(): void
    {
        $kernel = new TestKernel(self::ENABLED);

        $traceId = $kernel->handleWithoutTerminating('/boom')->headers->get('X-QA-Trace-Id');
        $context = $kernel->getContainer()->get('test.store')->claim($traceId, TestKernel::fingerprint());

        self::assertSame(\RuntimeException::class, $context['exception']['class']);
        self::assertContains('The template "invoice_v2" does not exist', array_column($context['logs'], 'message'));
    }

    public function testARequestThatSucceedsIsStoredOnlyWhenItTerminates(): void
    {
        $kernel = new TestKernel(self::ENABLED);

        $traceId = $kernel->handleWithoutTerminating('/ok')->headers->get('X-QA-Trace-Id');

        self::assertNull($kernel->getContainer()->get('test.store')->claim($traceId, TestKernel::fingerprint()));
    }

    public function testKeepsWarningsAndErrorsWhenTheLogLimitIsReached(): void
    {
        $context = $this->contextOf('/spam', [...self::ENABLED, 'logs' => ['max_records' => 5]]);

        self::assertCount(5, $context['logs']);
        self::assertContains('Something went wrong', array_column($context['logs'], 'message'));
        self::assertGreaterThanOrEqual(16, $context['logsDropped']);
    }

    public function testEachRequestStartsWithACleanContext(): void
    {
        $kernel = new TestKernel(self::ENABLED);
        $this->contextOf('/boom', kernel: $kernel);

        $second = $this->contextOf('/ok', kernel: $kernel);

        self::assertNull($second['exception']);
        self::assertNotContains('The template "invoice_v2" does not exist', array_column($second['logs'], 'message'));
    }

    public function testNothingIsStoredWhenDisabled(): void
    {
        self::assertNull($this->contextOf('/log', ['enabled' => false]));
    }
}
