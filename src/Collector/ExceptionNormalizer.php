<?php

namespace QaTracking\CaptureBundle\Collector;

/**
 * Converts an exception into a bounded array. It does not include the call
 * arguments: they can be large and carry sensitive data.
 */
final class ExceptionNormalizer
{
    private const MAX_FRAMES = 60;
    private const MAX_PREVIOUS = 5;

    public function __construct(
        private readonly ValueNormalizer $values,
        private readonly string $projectDir,
    ) {
    }

    /** @return array<string, mixed> */
    public function normalize(\Throwable $exception): array
    {
        $data = $this->describe($exception);
        $data['trace'] = $this->frames($exception);

        $previous = [];
        $current = $exception->getPrevious();
        while (null !== $current && \count($previous) < self::MAX_PREVIOUS) {
            $previous[] = $this->describe($current);
            $current = $current->getPrevious();
        }
        $data['previous'] = $previous;

        return $data;
    }

    /** @return array<string, mixed> */
    private function describe(\Throwable $exception): array
    {
        return [
            'class' => $exception::class,
            'message' => $this->values->text($exception->getMessage(), 4000),
            'code' => \is_int($exception->getCode()) ? $exception->getCode() : (string) $exception->getCode(),
            'file' => $this->relative($exception->getFile()),
            'line' => $exception->getLine(),
        ];
    }

    /** @return list<array{file: ?string, line: ?int, call: string}> */
    private function frames(\Throwable $exception): array
    {
        $frames = [];
        foreach (\array_slice($exception->getTrace(), 0, self::MAX_FRAMES) as $frame) {
            $frames[] = [
                'file' => isset($frame['file']) ? $this->relative($frame['file']) : null,
                'line' => $frame['line'] ?? null,
                'call' => ($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'].'()',
            ];
        }

        return $frames;
    }

    /** Paths are stored relative to the project: they are more readable and do not reveal the server. */
    private function relative(string $file): string
    {
        $prefix = rtrim($this->projectDir, '/').'/';

        return str_starts_with($file, $prefix) ? substr($file, \strlen($prefix)) : $file;
    }
}
