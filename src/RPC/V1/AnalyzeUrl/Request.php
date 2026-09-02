<?php

declare(strict_types=1);

namespace App\RPC\V1\AnalyzeUrl;

final class Request
{
    public function __construct(
        private string $url = '',
        private string $task = 'observe',
        private string $callbackUrl = '',
        private string $token = '',
        private bool $force = false,
    ) {
    }

    public function getUrl(): string
    {
        return trim($this->url);
    }

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }

    public function getTask(): string
    {
        return trim($this->task) ?: 'observe';
    }

    public function setTask(string $task): void
    {
        $this->task = $task;
    }

    public function getCallbackUrl(): string
    {
        return trim($this->callbackUrl);
    }

    public function setCallbackUrl(string $callbackUrl): void
    {
        $this->callbackUrl = $callbackUrl;
    }

    public function getToken(): string
    {
        return trim($this->token);
    }

    public function setToken(string $token): void
    {
        $this->token = $token;
    }

    public function isForce(): bool
    {
        return $this->force;
    }

    public function setForce(bool $force): void
    {
        $this->force = $force;
    }
}
