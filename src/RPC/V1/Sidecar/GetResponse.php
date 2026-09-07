<?php

declare(strict_types=1);

namespace App\RPC\V1\Sidecar;

final class GetResponse
{
    private bool $found = false;
    private ?array $data = null;
    private string $path = '';

    public function getFound(): bool { return $this->found; }
    public function setFound(bool $found): void { $this->found = $found; }

    public function getData(): ?array { return $this->data; }
    public function setData(?array $data): void { $this->data = $data; }

    /** Returned so a caller can log/debug the exact key without re-deriving it. */
    public function getPath(): string { return $this->path; }
    public function setPath(string $path): void { $this->path = $path; }
}
