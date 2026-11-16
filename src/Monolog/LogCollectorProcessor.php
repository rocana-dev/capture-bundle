<?php

namespace QaTracking\CaptureBundle\Monolog;

use QaTracking\CaptureBundle\Collector\RequestContextCollector;

/**
 * Monolog processor that copies each record into the context of the current
 * request. It is the same mechanism the Symfony profiler uses to get the
 * logs: it sees what the application already logs, on every channel, without
 * reading the files.
 *
 * No types in the signature on purpose: Monolog 2 passes an array and Monolog 3
 * a LogRecord, and both are read the same way by key.
 */
final class LogCollectorProcessor
{
    /** Channel on which Doctrine logs queries and the connection. */
    private const SQL_CHANNEL = 'doctrine';

    /** @param list<string> $excludedChannels */
    public function __construct(
        private readonly RequestContextCollector $collector,
        private readonly bool $enabled,
        private readonly array $excludedChannels,
        private readonly bool $includeSqlParams = false,
    ) {
    }

    /**
     * @param array<string, mixed>|\Monolog\LogRecord $record
     *
     * @return array<string, mixed>|\Monolog\LogRecord
     */
    public function __invoke($record)
    {
        if (!$this->enabled || !$this->collector->isActive()) {
            return $record;
        }

        try {
            $channel = (string) $record['channel'];
            if (\in_array($channel, $this->excludedChannels, true)) {
                return $record;
            }

            $context = \is_array($record['context']) ? $record['context'] : [];
            if (self::SQL_CHANNEL === $channel && !$this->includeSqlParams) {
                // A query's parameters are unmasked database data
                // (and the connection's are credentials): only the SQL is kept.
                unset($context['params'], $context['types']);
            }
            $datetime = $record['datetime'];

            $this->collector->addLog(
                (int) (\is_object($record['level']) ? $record['level']->value : $record['level']),
                (string) $record['level_name'],
                $channel,
                self::interpolate((string) $record['message'], $context),
                $context,
                $datetime instanceof \DateTimeInterface ? (float) $datetime->format('U.u') : microtime(true),
            );
        } catch (\Throwable) {
            // Capturing logs must never affect the application's logging.
        }

        return $record;
    }

    /**
     * Replaces PSR-3 `{key}` placeholders with their value.
     *
     * @param array<string, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            if (null === $value || \is_scalar($value) || $value instanceof \Stringable) {
                $replacements['{'.$key.'}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }
}
