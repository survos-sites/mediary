<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SourceBuckets;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;

final class SourceBucketsTest extends TestCase
{
    private function buckets(?string $config): SourceBuckets
    {
        $s3 = new S3Client(['version' => '2006-03-01', 'region' => 'fsn1', 'credentials' => false]);

        return new SourceBuckets($s3, 'https://fsn1.your-objectstorage.com', $config);
    }

    public function testVirtualHostAndPathStyleResolveUnderThePrefix(): void
    {
        $buckets = $this->buckets('survos-platform/vault/, nabolom');
        $expected = ['bucket' => 'survos-platform', 'key' => 'vault/mus/path-tobacco-ads/originals/21CSFB-0001.jpg'];

        self::assertSame($expected, $buckets->resolve('https://survos-platform.fsn1.your-objectstorage.com/vault/mus/path-tobacco-ads/originals/21CSFB-0001.jpg'));
        self::assertSame($expected, $buckets->resolve('https://fsn1.your-objectstorage.com/survos-platform/vault/mus/path-tobacco-ads/originals/21CSFB-0001.jpg'));
        self::assertSame(['bucket' => 'nabolom', 'key' => 'mapoteca/Mapa 1.jpg'], $buckets->resolve('https://nabolom.fsn1.your-objectstorage.com/mapoteca/Mapa%201.jpg'));
    }

    public function testAnythingOutsideTheConfiguredBucketsIsNotASource(): void
    {
        $buckets = $this->buckets('survos-platform/vault/');

        self::assertNull($buckets->resolve('https://survos-platform.fsn1.your-objectstorage.com/folio/mus/x.folio'), 'outside the prefix');
        self::assertNull($buckets->resolve('https://museado.fsn1.your-objectstorage.com/vault/a.jpg'), 'unlisted bucket');
        self::assertNull($buckets->resolve('http://survos-platform.fsn1.your-objectstorage.com/vault/a.jpg'), 'not https');
        self::assertNull($buckets->resolve('https://survos-platform.example.org/vault/a.jpg'), 'other host');
        self::assertNull($buckets->resolve('https://survos-platform.fsn1.your-objectstorage.com/vault/a.jpg?X-Amz-Signature=1'), 'presigned, not an identity');
        self::assertNull($buckets->resolve('https://survos-platform.fsn1.your-objectstorage.com/vault/../folio/x'), 'traversal');
        self::assertNull($buckets->resolve('https://survos-platform.fsn1.your-objectstorage.com/vault/'), 'a directory');
        self::assertNull($this->buckets(null)->resolve('https://survos-platform.fsn1.your-objectstorage.com/vault/a.jpg'), 'nothing configured');
    }
}
