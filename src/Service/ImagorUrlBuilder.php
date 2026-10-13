<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Signed URLs for the Survos imagor build (survos-sites/imagor) — the
 * proof-of-concept alternative to imgproxy Pro. Selected per request by
 * AssetRegistry when IMAGE_BACKEND=imagor; imgproxy stays the default.
 *
 * PoC home: this belongs in survos/imgproxy-bundle as a second backend once the
 * contract settles; kept app-local so the shared bundle is not touched yet.
 *
 * Differences from imgproxy that matter here:
 *  - imagor has no server-side presets, so presets are expanded HERE. The
 *    server holds the same list (IMAGOR_PRESETS in its Dockerfile) and only
 *    persists results whose options match a cached preset (tiny, thumb)
 *    byte-for-byte. A drifted string still renders; it just never caches.
 *  - Sources: our own buckets are read in place as "{bucket}/{key}" (the
 *    server's buckets.yaml is the allowlist); http(s) URLs are fully
 *    percent-encoded so '?', '(', ',' etc. can never be parsed as options.
 *  - Signature: base64url(HMAC-SHA256(secret, path)) truncated to 40 chars,
 *    where path is everything after the signature segment.
 */
final class ImagorUrlBuilder
{
    /**
     * MUST match IMAGOR_PRESETS in survos-sites/imagor's Dockerfile, which
     * mirrors IMGPROXY_PRESETS (same sizes, quality, webp, metadata kept).
     */
    public const PRESETS = [
        'tiny' => 'fit-in/200x200/filters:quality(70):format(webp)',
        'thumb' => 'fit-in/400x400/filters:quality(80):format(webp)',
        'observe' => 'fit-in/512x512/filters:quality(80):format(webp)',
        'display' => 'fit-in/600x400/filters:quality(80):format(webp)',
        'archive' => 'filters:quality(88):format(webp)',
        'card' => '800x600/filters:quality(82):format(webp):no_upscale()',
        'large' => 'fit-in/1600x1600/filters:quality(82):format(webp)',
    ];

    public function __construct(
        #[Autowire('%env(default::IMAGOR_HOST)%')] public readonly ?string $host = null,
        #[Autowire('%env(default::IMAGOR_SECRET)%')] private readonly ?string $secret = null,
        /**
         * Comma-separated buckets the server reads in place (its buckets.yaml).
         * Sources in other buckets fall back to imgproxy. Empty = any bucket.
         */
        #[Autowire('%env(default::IMAGOR_S3_BUCKETS)%')] private readonly ?string $s3Buckets = null,
        private readonly int $signerTruncate = 40,
        /** Our object storage; https URLs into it are rewritten to {bucket}/{key} (read in place, never copied). */
        #[Autowire('%env(default::S3_ENDPOINT)%')] private readonly ?string $s3Endpoint = null,
        /** S3_STORAGE_BASE_DIR on the server: where cached external originals live in museado. */
        private readonly string $sourcePrefix = 'imagor/src',
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->host && '' !== $this->host && null !== $this->secret && '' !== $this->secret;
    }

    public function hasPreset(string $preset): bool
    {
        return isset(self::PRESETS[$preset]);
    }

    /**
     * Whether imagor can read this source at all. s3:// URLs are only readable
     * for the server's allowed buckets; others stay on imgproxy.
     */
    public function supports(string $source): bool
    {
        return null !== $this->imagePath($source);
    }

    public function preset(string $source, string $preset = 'thumb'): string
    {
        if (!isset(self::PRESETS[$preset])) {
            throw new \InvalidArgumentException(sprintf('Unknown imagor preset "%s". Available: %s', $preset, implode(', ', array_keys(self::PRESETS))));
        }

        return $this->build($source, self::PRESETS[$preset]);
    }

    /** Arbitrary imagor options, e.g. "fit-in/1200x0/filters:format(webp)". Never cached server-side. */
    public function build(string $source, string $options): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('imagor is not configured. Set IMAGOR_HOST and IMAGOR_SECRET.');
        }
        $image = $this->imagePath($source) ?? throw new \InvalidArgumentException(sprintf('imagor cannot read source "%s".', $source));

        $path = trim($options, '/').'/'.$image;

        return rtrim((string) $this->host, '/').'/'.$this->sign($path).'/'.$path;
    }

    public function sign(string $path): string
    {
        $sig = strtr(base64_encode(hash_hmac('sha256', $path, (string) $this->secret, true)), '+/', '-_');

        return $this->signerTruncate > 0 ? substr($sig, 0, $this->signerTruncate) : $sig;
    }

    /**
     * Where imagor keeps its byte-identical copy of an EXTERNAL original, as an
     * s3://museado/… URL — computable from the source URL alone, readable
     * without imagor. Null for our own buckets: those are never copied, their
     * master IS the source. The object exists once imagor has served any
     * request for the source.
     */
    public function cachedSourceUrl(string $source, string $bucket = 'museado'): ?string
    {
        $source = $this->ownStorageS3Url($source) ?? $source;
        if (!preg_match('~^https?://~i', $source)) {
            return null;
        }
        $d = sha1($source);

        return sprintf('s3://%s/%s/%s/%s/%s', $bucket, trim($this->sourcePrefix, '/'), substr($d, 0, 2), substr($d, 2, 2), $d);
    }

    /**
     * https://fsn1.your-objectstorage.com/{bucket}/{key} and
     * https://{bucket}.fsn1.your-objectstorage.com/{key} → s3://{bucket}/{key}.
     * Presigned URLs are left alone (their query string is the access).
     */
    private function ownStorageS3Url(string $source): ?string
    {
        $endpoint = parse_url((string) $this->s3Endpoint, PHP_URL_HOST);
        $parts = parse_url($source);
        if (!$endpoint || !isset($parts['host'], $parts['path']) || isset($parts['query'])) {
            return null;
        }
        $path = ltrim(rawurldecode($parts['path']), '/');
        if (strcasecmp($parts['host'], $endpoint) === 0) {
            return str_contains($path, '/') ? 's3://'.$path : null;
        }
        $suffix = '.'.$endpoint;
        if (str_ends_with(strtolower($parts['host']), strtolower($suffix)) && '' !== $path) {
            return 's3://'.substr($parts['host'], 0, -strlen($suffix)).'/'.$path;
        }

        return null;
    }

    private function imagePath(string $source): ?string
    {
        $source = $this->ownStorageS3Url($source) ?? $source;

        if (str_starts_with($source, 's3://')) {
            [$bucket, $key] = array_pad(explode('/', substr($source, 5), 2), 2, '');
            $allowed = array_filter(array_map(trim(...), explode(',', (string) $this->s3Buckets)));
            if ('' === $bucket || '' === $key || ([] !== $allowed && !in_array($bucket, $allowed, true))) {
                return null;
            }

            // Encode each segment but keep the slashes: {bucket}/orig/ab/cd/<sha>.jpg
            return implode('/', array_map(rawurlencode(...), [$bucket, ...explode('/', $key)]));
        }

        if (preg_match('~^https?://~i', $source)) {
            return rawurlencode($source);
        }

        return null;
    }
}
