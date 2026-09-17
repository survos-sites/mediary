<?php

declare(strict_types=1);

namespace App\Service;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Private buckets whose objects mediary uses in place instead of archiving a copy.
 *
 * Some collections are on no website at all (PATH Tobacco Ads, Na Bolom's scans): their only
 * remote copy is a private bucket, usually the platform vault. Archiving them the usual way means
 * making the bucket public so mediary can GET the file, then storing it a second time under orig/.
 * For a bucket listed here, the asset instead points at the object where it is: imgproxy and /info
 * read s3://<bucket>/<key>, and anything that needs HTTP gets a presigned URL.
 *
 * The originalUrl is still an ordinary https URL (virtual-host or path-style on S3_ENDPOINT), so the
 * asset id and /batch are unchanged, and it answers 403 to anyone without our keys. Like
 * MEDIARY_OWNED_PDF_HOSTS, the list is operator configuration, never a caller hint:
 *
 *     MEDIARY_SOURCE_BUCKETS=survos-platform/vault/,nabolom
 *
 * An entry is a bucket, optionally with a key prefix the object must sit under.
 */
final class SourceBuckets
{
    /** @var list<array{bucket: string, prefix: string}> */
    private readonly array $entries;
    private readonly string $endpointHost;

    public function __construct(
        private readonly S3Client $s3,
        #[Autowire('%env(S3_ENDPOINT)%')] string $s3Endpoint,
        #[Autowire('%env(default::MEDIARY_SOURCE_BUCKETS)%')] ?string $sourceBuckets = null,
    ) {
        $this->endpointHost = strtolower((string) parse_url($s3Endpoint, PHP_URL_HOST));
        $entries = [];
        foreach (explode(',', (string) $sourceBuckets) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            [$bucket, $prefix] = array_pad(explode('/', $entry, 2), 2, '');
            $entries[] = ['bucket' => strtolower($bucket), 'prefix' => $prefix];
        }
        $this->entries = $entries;
    }

    /** @return array{bucket: string, key: string}|null the configured object this URL names */
    public function resolve(?string $url): ?array
    {
        if ($url === null || $this->entries === [] || $this->endpointHost === ''
            || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_QUERY) !== null) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = rawurldecode(ltrim((string) parse_url($url, PHP_URL_PATH), '/'));

        if (str_ends_with($host, '.' . $this->endpointHost)) {
            // https://<bucket>.fsn1.your-objectstorage.com/<key>
            $bucket = substr($host, 0, -strlen('.' . $this->endpointHost));
            $key = $path;
        } elseif ($host === $this->endpointHost) {
            // https://fsn1.your-objectstorage.com/<bucket>/<key>
            [$bucket, $key] = array_pad(explode('/', $path, 2), 2, '');
            $bucket = strtolower($bucket);
        } else {
            return null;
        }
        if ($key === '' || str_ends_with($key, '/') || str_contains('/' . $key . '/', '/../')) {
            return null;
        }
        foreach ($this->entries as $entry) {
            if ($entry['bucket'] === $bucket && str_starts_with($key, $entry['prefix'])) {
                return ['bucket' => $bucket, 'key' => $key];
            }
        }

        return null;
    }

    /**
     * HEAD the object with our keys.
     *
     * @return array{size: int, mime: ?string}|null null when the object does not exist
     */
    public function head(string $bucket, string $key): ?array
    {
        try {
            $result = $this->s3->headObject(['Bucket' => $bucket, 'Key' => $key]);
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            throw $e;
        }
        $mime = $result['ContentType'] ?? null;

        return ['size' => (int) $result['ContentLength'], 'mime' => is_string($mime) && $mime !== '' ? $mime : null];
    }
}
