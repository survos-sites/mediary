<?php

declare(strict_types=1);

namespace App\RPC\V1;

use App\RPC\V1\Sidecar\GetRequest;
use App\RPC\V1\Sidecar\GetResponse;
use App\Service\SidecarService;
use OV\JsonRPCAPIBundle\Core\Annotation\JsonRPCAPI;
use OV\JsonRPCAPIBundle\Core\ApiMethodInterface;

/**
 * Read an AI-task sidecar.
 *
 * SidecarService used to live in media-bundle and be injected directly by client apps, which
 * meant every app was a second writer into mediary's bucket namespace — holding its own S3
 * credentials and its own copy of the path convention. That is the same shape as an app keeping
 * a Media table alongside mediary's Asset: two owners of one thing, free to drift.
 *
 * Now mediary owns the sidecar store and apps ask for it. The path is still derived from shared
 * vocabulary (MediaKeyService, in data-contracts), so both sides agree on the key without either
 * depending on the other's code.
 *
 *   curl -X POST https://mediary.survos.com/api/v1 \
 *     -H 'Content-Type: application/json' \
 *     -d '{"jsonrpc":"2.0","method":"sidecarGet","params":{"id":"<mediaId>","task":"observe"},"id":"1"}'
 *
 * `found: false` is a normal answer, not an error — a sidecar that has not been produced yet is
 * the ordinary cache-miss case, and callers treat it as "go compute it".
 *
 * Deliberately no `remember()` equivalent over the wire: its compute-if-missing contract takes a
 * producer callable, which does not survive a network boundary. Callers keep the branch locally —
 * get, and on a miss compute then put. That also keeps the paid AI call on the side that wanted it.
 */
#[JsonRPCAPI(methodName: 'sidecarGet', type: 'POST')]
final readonly class SidecarGetMethod implements ApiMethodInterface
{
    public function __construct(private SidecarService $sidecar)
    {
    }

    public function call(GetRequest $request): GetResponse
    {
        $id = $request->getId();
        $task = $request->getTask();

        $response = new GetResponse();
        // Returned even on a miss so a caller can log the exact key it asked for without
        // re-deriving it — the diagnostic that turns "sidecar read failed" into something
        // actionable against the bucket.
        $response->setPath($this->sidecar->path($id, $task));

        $data = $this->sidecar->isAvailable() ? $this->sidecar->read($id, $task) : null;
        $response->setFound($data !== null);
        $response->setData($data);

        return $response;
    }
}
