<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;
use Aws\S3\S3Client;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A time-limited public URL for an asset's archived master, for services that must fetch the
 * bytes themselves.
 *
 * Our bucket is private (a plain GET of asset.archiveUrl answers 403), and a provider's batch
 * workers cannot use our credentials. The alternative -- sending the originalUrl and letting the
 * provider fetch the source -- puts the job at the mercy of whoever hosts it: Mistral's workers
 * could not fetch archive.org's IIIF endpoint at all (measured 2026-09-12), which would have
 * failed every page of a Soviet Life run. We already downloaded and archived those bytes once;
 * this hands them back out under a signature that expires.
 */
final class AssetPresigner
{
    /** Comfortably longer than a batch job's 24-hour window, short enough to be uninteresting. */
    public const int DEFAULT_TTL = 172800; // 48h

    public function __construct(
        private readonly S3Client $s3,
        #[Autowire('%env(AWS_S3_BUCKET_NAME)%')]
        private readonly string $bucket,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** Null when the asset has not been archived, or signing fails. */
    public function archiveUrl(Asset $asset, int $ttlSeconds = self::DEFAULT_TTL): ?string
    {
        $key = $asset->storageKey;
        if (!is_string($key) || $key === '') {
            return null;
        }

        try {
            $request = $this->s3->createPresignedRequest(
                $this->s3->getCommand('GetObject', ['Bucket' => $this->bucket, 'Key' => $key]),
                sprintf('+%d seconds', $ttlSeconds),
            );

            return (string) $request->getUri();
        } catch (\Throwable $e) {
            $this->logger->warning('presign failed for {id} ({key}): {err}', ['id' => $asset->id, 'key' => $key, 'err' => $e->getMessage()]);

            return null;
        }
    }
}
