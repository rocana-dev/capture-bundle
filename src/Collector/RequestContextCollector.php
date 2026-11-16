<?php

namespace QaTracking\CaptureBundle\Collector;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Gathers what happens during a request: exception, log records and, later
 * on, Doctrine entities. There is a single instance per container, so it is
 * reset between requests (needed in long-lived runtimes such as FrankenPHP
 * in worker mode).
 */
final class RequestContextCollector implements ResetInterface
{
    private const IMPORTANT_LEVEL = 300; // WARNING

    /** @var array<string, mixed>|null */
    private ?array $context = null;
    private float $startedAt = 0.0;
    /** @var list<array<string, mixed>> */
    private array $logs = [];
    private int $logsDropped = 0;
    /** @var array<string, array{class: string, id: array<string, mixed>, primary: bool, ops: array<string, true>}> */
    private array $entities = [];
    private int $entitiesDropped = 0;
    /** @var list<array<string, mixed>> */
    private array $changes = [];

    public function __construct(
        private readonly ValueNormalizer $values,
        private readonly ExceptionNormalizer $exceptions,
        private readonly int $maxLogs,
        private readonly int $maxEntities = 300,
        private readonly int $maxChanges = 200,
    ) {
    }

    public function start(string $traceId, Request $request, string $clientFingerprint): void
    {
        $this->reset();
        $this->startedAt = microtime(true);
        $this->context = [
            'traceId' => $traceId,
            'startedAt' => (int) round($this->startedAt * 1000),
            'client' => $clientFingerprint,
            'request' => [
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'query' => $this->values->query($request->query->all()),
            ],
            'response' => null,
            'exception' => null,
        ];
    }

    public function isActive(): bool
    {
        return null !== $this->context;
    }

    public function traceId(): ?string
    {
        return $this->context['traceId'] ?? null;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function addLog(int $level, string $levelName, string $channel, string $message, array $context, float $at): void
    {
        if (null === $this->context) {
            return;
        }

        if (\count($this->logs) >= $this->maxLogs && !$this->makeRoom($level)) {
            ++$this->logsDropped;

            return;
        }

        $this->logs[] = [
            'at' => (int) round($at * 1000),
            'level' => $levelName,
            'severity' => $level,
            'channel' => $channel,
            'message' => $this->values->text($message),
            'context' => [] === $context ? null : $this->values->normalize($context),
        ];
    }

    /**
     * Records that an entity was involved in the request. Once the limit is reached,
     * entities that were only read stop being recorded; primary and modified ones always go in.
     *
     * @param array<string, mixed> $id
     * @param string               $op read, insert, update or delete
     */
    public function addEntity(string $class, array $id, string $op, bool $primary = false): void
    {
        if (null === $this->context) {
            return;
        }

        $key = $class.'#'.json_encode($id);
        if (!isset($this->entities[$key])) {
            if ('read' === $op && !$primary && \count($this->entities) >= $this->maxEntities) {
                ++$this->entitiesDropped;

                return;
            }
            $this->entities[$key] = ['class' => $class, 'id' => $id, 'primary' => false, 'ops' => []];
        }

        $this->entities[$key]['ops'][$op] = true;
        $this->entities[$key]['primary'] = $this->entities[$key]['primary'] || $primary;
    }

    /** @param array<string, mixed> $change */
    public function addChange(array $change): void
    {
        if (null !== $this->context && \count($this->changes) < $this->maxChanges) {
            $this->changes[] = $change;
        }
    }

    /** The first exception is kept: later ones are usually a consequence of rendering the error. */
    public function setException(\Throwable $exception): void
    {
        if (null !== $this->context && null === $this->context['exception']) {
            $this->context['exception'] = $this->exceptions->normalize($exception);
        }
    }

    /**
     * Returns the context as it stands right now. It can be called more than
     * once: when an exception occurs and again when the request finishes.
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(Request $request, ?Response $response, ?string $user): ?array
    {
        if (null === $this->context) {
            return null;
        }

        $controller = $request->attributes->get('_controller');
        $context = $this->context;
        $context['request']['route'] = $request->attributes->get('_route');
        $context['request']['controller'] = \is_string($controller) ? $controller : (\is_array($controller) && \is_string($controller[1] ?? null) ? (\is_object($controller[0]) ? $controller[0]::class : (string) $controller[0]).'::'.$controller[1] : null);
        $context['request']['routeParams'] = $this->values->normalize((array) $request->attributes->get('_route_params', []));
        $context['request']['user'] = $user;
        $context['response'] = [
            'status' => $response?->getStatusCode(),
            'durationMs' => (int) round((microtime(true) - $this->startedAt) * 1000),
        ];
        $context['logs'] = $this->logs;
        $context['logsDropped'] = $this->logsDropped;
        $context['entities'] = array_map(
            static fn (array $entity) => [...$entity, 'ops' => array_keys($entity['ops'])],
            array_values($this->entities),
        );
        $context['entitiesDropped'] = $this->entitiesDropped;
        $context['changes'] = $this->changes;

        return $context;
    }

    public function reset(): void
    {
        $this->context = null;
        $this->logs = [];
        $this->logsDropped = 0;
        $this->entities = [];
        $this->entitiesDropped = 0;
        $this->changes = [];
        $this->startedAt = 0.0;
    }

    /**
     * Once the limit is reached, the oldest of the less important records is discarded.
     * A warning or error is never lost because of debug messages.
     */
    private function makeRoom(int $incomingLevel): bool
    {
        foreach ($this->logs as $index => $log) {
            if ($log['severity'] < self::IMPORTANT_LEVEL) {
                array_splice($this->logs, $index, 1);
                ++$this->logsDropped;

                return true;
            }
        }

        if ($incomingLevel >= self::IMPORTANT_LEVEL) {
            array_shift($this->logs);
            ++$this->logsDropped;

            return true;
        }

        return false;
    }
}
