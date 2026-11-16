<?php

namespace QaTracking\CaptureBundle\Collector;

/**
 * Makes any value ready to be stored as JSON: limits sizes and
 * depth, and masks anything that looks like a credential.
 */
final class ValueNormalizer
{
    public const MASK = '***';

    private const MAX_DEPTH = 4;
    private const MAX_ITEMS = 40;

    /** @param list<string> $sensitivePatterns key name fragments, case-insensitive */
    public function __construct(
        private readonly array $sensitivePatterns,
        private readonly int $maxStringLength = 2000,
    ) {
    }

    public function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach ($this->sensitivePatterns as $pattern) {
            if ('' !== $pattern && str_contains($key, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    public function text(string $value, ?int $maxLength = null): string
    {
        $maxLength ??= $this->maxStringLength;
        if (!mb_check_encoding($value, 'UTF-8')) {
            return \sprintf('[binary, %d bytes]', \strlen($value));
        }
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return mb_substr($value, 0, $maxLength).\sprintf('… [truncated, %d characters in total]', mb_strlen($value));
    }

    public function normalize(mixed $value, int $depth = 0): mixed
    {
        if (null === $value || \is_bool($value) || \is_int($value)) {
            return $value;
        }
        if (\is_float($value)) {
            return is_finite($value) ? $value : (string) $value;
        }
        if (\is_string($value)) {
            return $this->text($value);
        }
        if (\is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return \sprintf('[array of %d items]', \count($value));
            }

            $result = [];
            foreach (\array_slice($value, 0, self::MAX_ITEMS, true) as $key => $item) {
                $result[$key] = \is_string($key) && $this->isSensitiveKey($key) ? self::MASK : $this->normalize($item, $depth + 1);
            }
            if (\count($value) > self::MAX_ITEMS) {
                $result['…'] = \sprintf('%d more items', \count($value) - self::MAX_ITEMS);
            }

            return $result;
        }
        if ($value instanceof \Throwable) {
            return \sprintf('%s: %s', $value::class, $this->text($value->getMessage(), 500));
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DATE_ATOM);
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if ($value instanceof \Stringable) {
            try {
                return $this->text((string) $value);
            } catch (\Throwable) {
                return \sprintf('[%s]', $value::class);
            }
        }
        if (\is_object($value)) {
            return \sprintf('[%s]', $value::class);
        }
        if (\is_resource($value)) {
            return '[resource]';
        }

        return '[value cannot be represented]';
    }

    /**
     * Masks the sensitive values of a query string.
     *
     * @param array<string, mixed> $query
     */
    public function query(array $query): ?string
    {
        if ([] === $query) {
            return null;
        }

        $masked = [];
        foreach ($query as $key => $value) {
            $masked[$key] = $this->isSensitiveKey((string) $key) ? self::MASK : $value;
        }

        return $this->text(http_build_query($masked), 1000);
    }
}
