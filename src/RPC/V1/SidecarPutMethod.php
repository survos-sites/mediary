<?php

declare(strict_types=1);

namespace App\RPC\V1;

use App\RPC\V1\Sidecar\PutRequest;
use App\RPC\V1\Sidecar\PutResponse;
use App\Service\SidecarService;
use OV\JsonRPCAPIBundle\Core\Annotation\JsonRPCAPI;
use OV\JsonRPCAPIBundle\Core\ApiMethodInterface;

/**
 * Write an AI-task sidecar. See {@see SidecarGetMethod} for why this is an endpoint rather than
 * an injected service.
 *
 *   curl -X POST https://mediary.survos.com/api/v1 \
 *     -H 'Content-Type: application/json' \
 *     -d '{"jsonrpc":"2.0","method":"sidecarPut",
 *          "params":{"id":"<mediaId>","task":"observe","data":{"caption":"…"}},"id":"1"}'
 *
 * Implemented via SidecarService::remember() with force: true — the service exposes no bare
 * write, and remember() with a constant producer is exactly "store this", so this avoids adding
 * a second write path that could diverge from the one AssetAiExecutor already uses.
 *
 * Overwrites deliberately. A sidecar is a cache of one task's result for one media id; the
 * caller re-running the task is the authority on what that result now is. Versioning belongs in
 * claims, which keep provenance, not here.
 */
#[JsonRPCAPI(methodName: 'sidecarPut', type: 'POST')]
final readonly class SidecarPutMethod implements ApiMethodInterface
{
    public function __construct(private SidecarService $sidecar)
    {
    }

    public function call(PutRequest $request): PutResponse
    {
        $id = $request->getId();
        $task = $request->getTask();

        $response = new PutResponse();
        $response->setPath($this->sidecar->path($id, $task));

        if (!$this->sidecar->isAvailable()) {
            // Storage not configured is a real answer, not an exception: the caller asked us to
            // cache something and we could not, which is survivable — the result it just computed
            // is still valid, it simply will not be there next time.
            $response->setStored(false);

            return $response;
        }

        $data = $request->getData();
        $this->sidecar->remember($id, $task, static fn (): array => $data, force: true);
        $response->setStored(true);

        return $response;
    }
}
