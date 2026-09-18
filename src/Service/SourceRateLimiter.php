<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\Exception\MaxWaitDurationExceededException;
use Symfony\Component\RateLimiter\Policy\Rate;
use Symfony\Component\RateLimiter\Policy\TokenBucketLimiter;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Per-host pacing for fetches from source servers, shared by every archive worker.
 *
 * Four archive containers pulling one small library host at once is a burst it answers with 429
 * (washingtonpublib.libraryhost.com, 20 of 100 omeka/wej archives on 2026-09-18). Each fetch
 * reserves a slot on its host's token bucket and waits for it. The buckets live in Postgres (a
 * DBAL cache pool, guarded by an advisory lock) because that is the one store the containers
 * share: the app cache is APCu and the default lock is flock, both per container.
 *
 * The rate is requests per second. The producer may send one per item (MediaSyncKeys::SOURCE_RATE,
 * set by the dataset's ResolveAiTasksEvent listener in harvest); otherwise the configured default
 * applies. When two rates reach the same host, the bucket is keyed by rate too, so a slow source
 * never borrows a fast one's budget -- the slower caller simply paces itself.
 */
final class SourceRateLimiter
{
    public function __construct(
        #[Autowire(service: 'cache.source_rate')]
        private readonly CacheItemPoolInterface $pool,
        #[Autowire(service: 'lock.source_rate.factory')]
        private readonly LockFactory $lockFactory,
        #[Autowire('%env(float:MEDIARY_SOURCE_RATE_DEFAULT)%')]
        private readonly float $defaultRate,
        #[Autowire('%env(int:MEDIARY_SOURCE_RATE_MAX_WAIT)%')]
        private readonly int $maxWaitSeconds,
    ) {
    }

    /**
     * Block until this host may be fetched again. A rate <= 0 means unpaced.
     *
     * @throws MaxWaitDurationExceededException when the host is so backed up that the slot is more
     *         than maxWaitSeconds away; the message is retried later instead of holding the worker.
     */
    public function waitFor(string $url, ?float $rate = null): void
    {
        $rate = $rate !== null && $rate > 0 ? $rate : $this->defaultRate;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($rate <= 0 || $host === '') {
            return;
        }

        [$refill, $burst] = $rate >= 1
            ? [Rate::perSecond((int) floor($rate)), (int) floor($rate)]
            : [new Rate(new \DateInterval(sprintf('PT%dS', (int) ceil(1 / $rate))), 1), 1];

        $limiter = new TokenBucketLimiter(
            sprintf('source:%s:%s', $host, $refill),
            $burst,
            $refill,
            new CacheStorage($this->pool),
            $this->lockFactory->createLock('source_rate:' . $host),
        );
        $limiter->reserve(1, $this->maxWaitSeconds)->wait();
    }
}
