<?php

namespace QaTracking\CaptureBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use QaTracking\CaptureBundle\Tests\Fixtures\Entity\Customer;
use QaTracking\CaptureBundle\Tests\Fixtures\Entity\Invoice;
use QaTracking\CaptureBundle\Tests\Fixtures\Entity\InvoiceLine;
use QaTracking\CaptureBundle\Tests\TestKernel;

final class DoctrineCaptureTest extends TestCase
{
    private const CONFIG = [
        'enabled' => true,
        'allowed_origins' => [TestKernel::CORS_ORIGIN],
        'tracker_url' => 'https://tracker.example.com',
        'project_secret' => TestKernel::SECRET,
    ];
    private const ORIGIN = ['Origin' => TestKernel::CORS_ORIGIN];

    protected function setUp(): void
    {
        TestKernel::resetTracker();
    }

    /** @param array<string, mixed> $config */
    private function kernel(array $config = []): TestKernel
    {
        $kernel = new TestKernel([...self::CONFIG, ...$config], 'test', true);
        $kernel->createDatabase();

        return $kernel;
    }

    /** @return array<string, mixed> */
    private function contextOf(TestKernel $kernel, string $path): array
    {
        $traceId = $kernel->get($path, self::ORIGIN)->headers->get('X-QA-Trace-Id');

        return $kernel->getContainer()->get('test.store')->claim($traceId, TestKernel::fingerprint());
    }

    /**
     * Makes the requests, asks for the context like the capture bar and returns what the tracker received.
     *
     * @param list<string> $paths
     *
     * @return array{payload: array<string, mixed>, browser: array<string, mixed>}
     */
    private function report(TestKernel $kernel, array $paths): array
    {
        $traceIds = array_map(static fn (string $path) => $kernel->get($path, self::ORIGIN)->headers->get('X-QA-Trace-Id'), $paths);
        $response = $kernel->request('POST', '/_qa/context', self::ORIGIN, json_encode(['traceIds' => $traceIds]));

        return [
            'payload' => TestKernel::$trackerRequests[0]['body'] ?? [],
            'browser' => json_decode((string) $response->getContent(), true),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function ofClass(array $items, string $class): array
    {
        return array_values(array_filter($items, static fn (array $item) => $class === $item['class']));
    }

    public function testTheEntityOfTheScreenIsPrimaryAndWhatItLoadsIsRecordedAsRead(): void
    {
        $entities = $this->contextOf($this->kernel(), '/invoices/1')['entities'];

        $invoice = $this->ofClass($entities, Invoice::class)[0];
        self::assertSame(['id' => 1], $invoice['id']);
        self::assertTrue($invoice['primary']);
        self::assertSame(['read'], $invoice['ops']);

        $customer = $this->ofClass($entities, Customer::class)[0];
        self::assertFalse($customer['primary']);
        self::assertSame(['read'], $customer['ops']);

        self::assertCount(2, $this->ofClass($entities, InvoiceLine::class));
    }

    public function testEntitiesLoadedByAListAreNotPrimary(): void
    {
        $entities = $this->contextOf($this->kernel(), '/invoices')['entities'];

        self::assertFalse($this->ofClass($entities, Invoice::class)[0]['primary']);
    }

    public function testRecordsWhatChangedBeforeAndAfter(): void
    {
        $context = $this->contextOf($this->kernel(), '/invoices/1/pay');

        $change = $this->ofClass($context['changes'], Invoice::class)[0];
        self::assertSame('update', $change['op']);
        self::assertSame(['id' => 1], $change['id']);
        self::assertSame(['status' => ['old' => 'issued', 'new' => 'paid']], $change['fields']);
        self::assertContains('update', $this->ofClass($context['entities'], Invoice::class)[0]['ops']);

        $secret = $this->ofClass($context['changes'], Customer::class)[0];
        self::assertSame(['apiToken' => ['old' => '***', 'new' => '***']], $secret['fields'], 'A sensitive field is recorded as changed without revealing its values.');
    }

    public function testKeepsTheDataOfDeletedRows(): void
    {
        $context = $this->contextOf($this->kernel(), '/invoices/1/remove-line');

        $change = $this->ofClass($context['changes'], InvoiceLine::class)[0];
        self::assertSame('delete', $change['op']);
        self::assertSame('Annual license', $change['data']['description']);
        self::assertSame(1, $change['data']['invoice'], 'A relation is stored by its identifier.');
    }

    public function testRecordsInsertsWithTheirNewIdentifier(): void
    {
        $context = $this->contextOf($this->kernel(), '/customers/new');

        self::assertSame(
            [['op' => 'insert', 'class' => Customer::class, 'id' => ['id' => 2]]],
            $context['changes'],
        );
    }

    public function testTheReportCarriesTheRowsOfTheEntitiesInvolved(): void
    {
        $result = $this->report($this->kernel(['masking' => ['entities' => [Customer::class => ['taxId']]]]), ['/invoices/1']);
        $rows = $result['payload']['rows'];

        self::assertSame(Invoice::class, $rows[0]['class'], 'The entity shown on the screen comes first.');
        self::assertTrue($rows[0]['primary']);
        self::assertSame('ok', $rows[0]['status']);
        self::assertSame('test_invoice', $rows[0]['tables'][0]['table']);
        // The exact order depends on how each Doctrine version creates the table; the key comes first.
        self::assertSame('id', $rows[0]['tables'][0]['columns'][0]);
        self::assertEqualsCanonicalizing(['id', 'customer_id', 'number', 'status'], $rows[0]['tables'][0]['columns']);
        self::assertEquals(['id' => 1, 'number' => 'F001-0023', 'status' => 'issued', 'customer_id' => 1], $rows[0]['tables'][0]['data']);

        $customer = $this->ofClass($rows, Customer::class)[0]['tables'][0]['data'];
        self::assertSame('Paper and Ink Bookshop', $customer['name']);
        self::assertSame('***', $customer['tax_id'], 'Masked by the entity rule.');
        self::assertSame('***', $customer['api_token'], 'Masked because it looks like a credential.');

        self::assertCount(2, $this->ofClass($rows, InvoiceLine::class));
        self::assertSame(0, $result['payload']['rowsOmitted']);
        self::assertEqualsWithDelta(microtime(true) * 1000, $result['payload']['rowsCapturedAt'], 5000);

        self::assertSame(4, $result['browser']['summary']['rows']);
        self::assertSame(['contextToken', 'summary'], array_keys($result['browser']), 'Rows are not returned to the browser.');
    }

    public function testAnEntitySeenInSeveralRequestsIsSentOnce(): void
    {
        $rows = $this->report($this->kernel(), ['/invoices/1', '/invoices/1', '/invoices'])['payload']['rows'];

        self::assertCount(1, $this->ofClass($rows, Invoice::class));
        self::assertTrue($this->ofClass($rows, Invoice::class)[0]['primary']);
    }

    public function testRowsAreCappedKeepingTheMostRelevant(): void
    {
        $payload = $this->report($this->kernel(['doctrine' => ['max_rows' => 1]]), ['/invoices/1'])['payload'];

        self::assertCount(1, $payload['rows']);
        self::assertSame(Invoice::class, $payload['rows'][0]['class']);
        self::assertSame(3, $payload['rowsOmitted']);
    }

    public function testReadRowsCanBeLeftOut(): void
    {
        $rows = $this->report($this->kernel(['doctrine' => ['include_read_rows' => false]]), ['/invoices/1/pay'])['payload']['rows'];

        self::assertEqualsCanonicalizing([Invoice::class, Customer::class], array_column($rows, 'class'), 'The primary and the modified ones remain, not the lines.');
    }

    public function testExcludedEntitiesAreSentAsAReferenceOnly(): void
    {
        $rows = $this->report($this->kernel(['masking' => ['exclude_entities' => [Customer::class]]]), ['/invoices/1'])['payload']['rows'];
        $customer = $this->ofClass($rows, Customer::class)[0];

        self::assertSame('excluded', $customer['status']);
        self::assertSame([], $customer['tables']);
        self::assertSame(['id' => 1], $customer['id']);
    }

    public function testAnAllowListLimitsWhatIsSent(): void
    {
        $rows = $this->report($this->kernel(['masking' => ['only' => [Customer::class => ['id', 'name']]]]), ['/invoices/1'])['payload']['rows'];

        self::assertSame(
            ['id' => 1, 'name' => 'Paper and Ink Bookshop', 'tax_id' => '***', 'api_token' => '***'],
            $this->ofClass($rows, Customer::class)[0]['tables'][0]['data'],
        );
    }

    public function testARowDeletedBeforeTheReportIsMarkedAsNotFound(): void
    {
        $rows = $this->report($this->kernel(), ['/invoices/1/remove-line'])['payload']['rows'];
        $statuses = array_column($this->ofClass($rows, InvoiceLine::class), 'status');

        self::assertContains('not_found', $statuses);
        self::assertContains('ok', $statuses);
    }

    public function testEntityTrackingCanBeTurnedOff(): void
    {
        $kernel = $this->kernel(['doctrine' => ['enabled' => false]]);

        self::assertSame([], $this->contextOf($kernel, '/invoices/1')['entities']);
        self::assertArrayNotHasKey('rows', $this->report($kernel, ['/invoices/1'])['payload']);
    }
}
