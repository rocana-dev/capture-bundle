<?php

namespace QaTracking\CaptureBundle\Trace;

/**
 * Identifier of a request. It is unpredictable (128 random bits) because
 * whoever knows it can ask for that request's context to be attached to a report.
 */
final class TraceId
{
    public const PATTERN = '/^[0-9a-f]{32}$/';

    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isValid(mixed $value): bool
    {
        return \is_string($value) && 1 === preg_match(self::PATTERN, $value);
    }
}
