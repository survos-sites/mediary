<?php

declare(strict_types=1);

namespace App\RPC\V1\ProbeAssets;

/**
 * params for the `probeAssets` JSON-RPC method.
 *
 * Deliberately array-shaped: this is the experiment's whole point. The REST equivalent
 * (POST /fetch/media/by-ids) hand-parses `$request->getContent()`, json_decodes it, and
 * filters the list itself; here the bundle deserializes and type-checks params into this
 * object before the method runs, and a JSON-RPC *batch* (an array of request objects) is
 * handled natively on top.
 */
final class Request
{
    /** @var list<string> */
    private array $ids = [];

    /**
     * Shared secret, checked by ProbeAssetsMethod. A probe returns titles, descriptions, OCR
     * text, AI output and storage URLs, so it is a read of the archive's contents, not a health
     * check -- and /api/v1 is PUBLIC_ACCESS at the firewall, so nothing else stands in front of
     * it. analyzeUrl already works this way; this brings the read side in line.
     */
    private string $token = '';

    /** @param list<string> $ids 16-hex asset ids */
    public function __construct(array $ids = [], string $token = '')
    {
        $this->ids = $ids;
        $this->token = $token;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): void
    {
        $this->token = $token;
    }

    /** @return list<string> */
    public function getIds(): array
    {
        return $this->ids;
    }

    /** @param list<string> $ids */
    public function setIds(array $ids): void
    {
        $this->ids = $ids;
    }
}
