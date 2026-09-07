<?php

declare(strict_types=1);

namespace App\RPC\V1\Sidecar;

final class PutResponse
{
    private bool $stored = false;
    private string $path = '';

    public function getStored(): bool { return $this->stored; }
    public function setStored(bool $stored): void { $this->stored = $stored; }

    public function getPath(): string { return $this->path; }
    public function setPath(string $path): void { $this->path = $path; }
}
