<?php

namespace QaTracking\CaptureBundle\Context;

use QaTracking\CaptureBundle\Doctrine\RowSnapshotter;
use QaTracking\CaptureBundle\Storage\ContextStore;
use QaTracking\CaptureBundle\Trace\TraceId;

/** Builds what is sent to the tracker from the trace IDs the capture bar saw. */
final class ContextAssembler
{
    public function __construct(
        private readonly ContextStore $store,
        private readonly int $maxTraceIds,
        private readonly ?RowSnapshotter $rows = null,
    ) {
    }

    /**
     * @param array<mixed> $traceIds
     *
     * @return array{payload: array<string, mixed>, summary: array<string, int>}
     */
    public function assemble(array $traceIds, ?string $clientFingerprint): array
    {
        $valid = array_values(array_unique(array_filter($traceIds, [TraceId::class, 'isValid'])));
        $valid = \array_slice($valid, 0, $this->maxTraceIds);

        $contexts = [];
        foreach ($valid as $traceId) {
            if (null !== $context = $this->store->claim($traceId, $clientFingerprint)) {
                $contexts[] = $context;
            }
        }
        usort($contexts, static fn (array $a, array $b) => ($a['startedAt'] ?? 0) <=> ($b['startedAt'] ?? 0));

        $payload = ['contexts' => $contexts];
        $rowCount = 0;
        if (null !== $this->rows && [] !== $contexts) {
            try {
                $snapshot = $this->rows->snapshot($contexts);
                $payload['rows'] = $snapshot['rows'];
                $payload['rowsOmitted'] = $snapshot['omitted'];
                $payload['rowsCapturedAt'] = $snapshot['capturedAt'];
                $rowCount = \count(array_filter($snapshot['rows'], static fn (array $row) => 'ok' === $row['status']));
            } catch (\Throwable) {
                // Without rows, the report still carries the exception and logs.
            }
        }

        return [
            'payload' => $payload,
            'summary' => [
                'requests' => \count($contexts),
                'errors' => \count(array_filter($contexts, static fn (array $c) => null !== ($c['exception'] ?? null))),
                'logs' => array_sum(array_map(static fn (array $c) => \count($c['logs'] ?? []), $contexts)),
                'rows' => $rowCount,
                'changes' => array_sum(array_map(static fn (array $c) => \count($c['changes'] ?? []), $contexts)),
            ],
        ];
    }
}
