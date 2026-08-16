<?php

declare(strict_types=1);

namespace App\RPC\V1\ProbeAssets;

final class Response
{
    /** @var list<array<string,mixed>> */
    private array $assets = [];

    private int $found = 0;

    /** @var list<string> ids that matched no asset -- explicit, not a silent short array */
    private array $missing = [];

    /** @return list<array<string,mixed>> */
    public function getAssets(): array
    {
        return $this->assets;
    }

    /** @param list<array<string,mixed>> $assets */
    public function setAssets(array $assets): void
    {
        $this->assets = $assets;
        $this->found  = \count($assets);
    }

    public function getFound(): int
    {
        return $this->found;
    }

    public function setFound(int $found): void
    {
        $this->found = $found;
    }

    /** @return list<string> */
    public function getMissing(): array
    {
        return $this->missing;
    }

    /** @param list<string> $missing */
    public function setMissing(array $missing): void
    {
        $this->missing = $missing;
    }
}
