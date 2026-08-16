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

    /** @param list<string> $ids 16-hex asset ids */
    public function __construct(array $ids = [])
    {
        $this->ids = $ids;
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
