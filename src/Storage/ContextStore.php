<?php

namespace QaTracking\CaptureBundle\Storage;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\PruneableInterface;

/**
 * Stores each request's context by its trace ID, for a short time.
 * Uses a PSR-6 pool: files by default, or Redis if the application runs on
 * several nodes (the original request and the context request may land on
 * different nodes).
 */
final class ContextStore
{
    private const KEY_PREFIX = 'qa.ctx.';
    private const RATE_PREFIX = 'qa.rate.';

    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly int $ttl,
        private readonly int $maxClaims,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function save(array $context): void
    {
        $item = $this->pool->getItem(self::KEY_PREFIX.$context['traceId']);
        $previous = $item->isHit() && \is_array($item->get()) ? $item->get() : [];

        // The same request is saved twice when it fails (on failure and on finishing):
        // the expiry and claims from the first time are kept.
        $context['claims'] = (int) ($previous['claims'] ?? 0);
        $context['expiresAt'] = (int) ($previous['expiresAt'] ?? time() + $this->ttl);

        $item->set($context);
        $item->expiresAt(new \DateTimeImmutable('@'.$context['expiresAt']));
        $this->pool->save($item);

        // The filesystem adapter does not delete expired items on its own.
        if ($this->pool instanceof PruneableInterface && 0 === random_int(0, 199)) {
            $this->pool->prune();
        }
    }

    /**
     * Hands over a request's context if the one asking is the same browser
     * that made it and the delivery limit has not been exceeded.
     *
     * @param string|null $clientFingerprint `null` disables the check
     *
     * @return array<string, mixed>|null
     */
    public function claim(string $traceId, ?string $clientFingerprint): ?array
    {
        $item = $this->pool->getItem(self::KEY_PREFIX.$traceId);
        $context = $item->isHit() ? $item->get() : null;
        if (!\is_array($context)) {
            return null;
        }
        if (null !== $clientFingerprint && !hash_equals((string) ($context['client'] ?? ''), $clientFingerprint)) {
            return null;
        }
        if (($context['claims'] ?? 0) >= $this->maxClaims) {
            return null;
        }

        ++$context['claims'];
        $item->set($context);
        $item->expiresAt(new \DateTimeImmutable('@'.(int) $context['expiresAt']));
        $this->pool->save($item);

        unset($context['client'], $context['claims'], $context['expiresAt']);

        return $context;
    }

    /** Counts an attempt within a fixed time window and says whether it is still allowed. */
    public function allow(string $bucket, int $limit, int $windowSeconds): bool
    {
        $item = $this->pool->getItem(self::RATE_PREFIX.$bucket.'.'.intdiv(time(), $windowSeconds));
        $count = $item->isHit() ? (int) $item->get() : 0;
        if ($count >= $limit) {
            return false;
        }

        $item->set($count + 1);
        $item->expiresAfter($windowSeconds * 2);
        $this->pool->save($item);

        return true;
    }
}
