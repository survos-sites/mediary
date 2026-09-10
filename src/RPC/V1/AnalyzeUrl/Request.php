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
        /**
         * Dataset scope for the claims this task persists, e.g. "mus/youtube". Without it the
         * claims land unscoped and `claims:fetch <dataset>` cannot pull them back -- the REST
         * route this method replaces accepted ?scope= for exactly that reason, so dropping it
         * would have quietly changed behaviour rather than merely changed transport.
         *
         * Nullable because that is the ONLY thing the RPC layer reads as "optional": MethodSpec
         * wraps a param in Assert\Optional when its type allows null and ignores defaults, so
         * `string $scope = ''` made scope mandatory and every existing caller (ssai's narration
         * transcription, depot's capture:enrich) started failing with "[scope] - This field is
         * missing." Omitted still means unscoped, exactly as before scope existed.
         */
        private ?string $scope = null,
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

    public function getScope(): string
    {
        return trim($this->scope ?? '');
    }

    public function setScope(?string $scope): void
    {
        $this->scope = $scope;
    }
}
