<?php

declare(strict_types=1);

namespace App\RPC\V1\Sidecar;

final class GetRequest
{
    private string $id = '';
    private string $task = '';

    public function getId(): string { return $this->id; }
    public function setId(string $id): void { $this->id = $id; }

    public function getTask(): string { return $this->task; }
    public function setTask(string $task): void { $this->task = $task; }
}
