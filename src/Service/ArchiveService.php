<?php
declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;
use Survos\DataContracts\Util\MediaKeyService;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use function rtrim;
use function sha1;
use function substr;
use function trim;

final class ArchiveService
{
    public function __construct(
    ) {
    }

    public function keyForUrl(string $url): string
    {
        return MediaKeyService::keyFromString($url);
    }

    public function payloadPath(string $key, string $extension): string
    {
        $extension = trim($extension, '.');
        if ($extension === '') {
            throw new InvalidArgumentException('Extension must not be empty.');
        }

        return $this->basePathForKey($key) . '/' . $key . '.' . $extension;
    }

    public function metaPath(string $key): string
    {
        return $this->basePathForKey($key) . '/' . $key . '.meta.json';
    }

    public function tempPayloadPath(string $key): string
    {
        return $this->basePathForKey($key) . '/.' . $key . '.tmp';
    }

    private function basePathForKey(string $key): string
    {
        if ($key === '') {
            throw new InvalidArgumentException('Key must not be empty.');
        }

        $hash = sha1($key);
        $a = substr($hash, 0, 2);
        $b = substr($hash, 2, 2);

        return $a . '/' . $b;
    }
}
