<?php

declare(strict_types=1);

namespace App\RPC\V1\AnalyzeUrl;

final class Response
{
    private ?string $id = null;
    private string $url = '';
    private string $task = '';
    private bool $cached = false;
    /** @var array<string,mixed> */
    private array $result = [];

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): void
    {
        $this->id = $id;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getTask(): string
    {
        return $this->task;
    }

    public function setTask(string $task): void
    {
        $this->task = $task;
    }

    public function isCached(): bool
    {
        return $this->cached;
    }

    public function setCached(bool $cached): void
    {
        $this->cached = $cached;
    }

    /** @return array<string,mixed> */
    public function getResult(): array
    {
        return $this->result;
    }

    /** @param array<string,mixed> $result */
    public function setResult(array $result): void
    {
        $this->result = $result;
    }
}
