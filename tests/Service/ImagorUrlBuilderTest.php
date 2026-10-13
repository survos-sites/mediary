<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ImagorUrlBuilder;
use PHPUnit\Framework\TestCase;

final class ImagorUrlBuilderTest extends TestCase
{
    private function builder(): ImagorUrlBuilder
    {
        return new ImagorUrlBuilder('http://localhost:8000/', 'localsecret', 'museado, mediary-dev');
    }

    public function testS3SourceIsBucketSlashKey(): void
    {
        // Expected signature computed independently:
        //   printf '%s' "$path" | openssl dgst -sha256 -hmac localsecret -binary | base64 | tr '+/' '-_' | cut -c1-40
        self::assertSame(
            'http://localhost:8000/2lmwRkMeL8mI3CeMkft3yEMvHvuf-XvOfezK6qBm/fit-in/400x400/filters:quality(80):format(webp)/museado/orig/te/st/photo.jpg',
            $this->builder()->preset('s3://museado/orig/te/st/photo.jpg', 'thumb'),
        );
    }

    public function testHttpSourceIsFullyEncodedSoItCannotBeParsedAsOptions(): void
    {
        self::assertSame(
            'http://localhost:8000/zAOVEjik_zhL1NUThp4dC9yUyXosSf1G2Apm5AjI/fit-in/600x400/filters:quality(80):format(webp)/https%3A%2F%2Fexample.com%2Fa%20b.jpg%3Fv%3D1%26w%3D2',
            $this->builder()->preset('https://example.com/a b.jpg?v=1&w=2', 'display'),
        );
    }

    public function testOtherBucketsAreNotSupported(): void
    {
        $b = $this->builder();
        self::assertFalse($b->supports('s3://survos-platform/vault/a.jpg'));
        self::assertFalse($b->supports('s3://museado/'));
        self::assertFalse($b->supports('file:///etc/passwd'));
        self::assertTrue($b->supports('s3://museado/orig/a.jpg'));
        self::assertTrue($b->supports('s3://mediary-dev/orig/a.jpg'));
        self::assertTrue($b->supports('https://example.com/a.jpg'));

        $this->expectException(\InvalidArgumentException::class);
        $b->preset('s3://survos-platform/vault/a.jpg');
    }

    public function testUnknownPresetIsAnError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->builder()->preset('https://example.com/a.jpg', 'huge');
    }

    public function testUnconfiguredBuilderRefusesToBuild(): void
    {
        $b = new ImagorUrlBuilder(null, null);
        self::assertFalse($b->isConfigured());
        $this->expectException(\RuntimeException::class);
        $b->preset('https://example.com/a.jpg');
    }
}
