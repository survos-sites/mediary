<?php

declare(strict_types=1);

namespace App\RPC\V1;

use App\RPC\V1\ProbeAssets\Request;
use App\RPC\V1\ProbeAssets\Response;
use App\Service\AssetProbeService;
use OV\JsonRPCAPIBundle\Core\Annotation\JsonRPCAPI;
use OV\JsonRPCAPIBundle\Core\ApiMethodInterface;

/**
 * EXPERIMENTAL. The first JSON-RPC method in mediary, and the proving ground for whether
 * otezvikentiy/json-rpc-api should take over the hand-rolled internal endpoints.
 *
 * Chosen deliberately: this is the read-only half of the media:sync contract, so getting
 * it wrong costs nothing. It mirrors media-bundle's MediaBatchDispatcher::probeMany(),
 * which today POSTs {"ids": [...]} to /fetch/media/by-ids and hand-parses the body on
 * both ends. Both transports share {@see AssetProbeService} so they cannot drift.
 *
 *   curl -X POST https://mediary.wip/api/v1 \
 *     -H 'Content-Type: application/json' \
 *     -d '{"jsonrpc":"2.0","method":"probeAssets","params":{"ids":["<id>"]},"id":"1"}'
 *
 * What is being evaluated:
 *   - params arrive deserialized and type-checked, instead of json_decode + array_filter;
 *   - a JSON-RPC *batch* (an array of request objects) is handled by the bundle, so
 *     several probes -- or a probe alongside some future method -- travel in one request;
 *   - errors come back as JSON-RPC error objects with codes rather than ad-hoc
 *     {"error": "..."} bodies at assorted HTTP statuses.
 *
 * Not yet migrated, on purpose: the batch push (POST /{client}/batch). It is the hot path
 * for every media:sync run and BatchController is currently unauthenticated -- that wants
 * the bundle's auth story settled first, which is the actual reason to adopt it.
 */
#[JsonRPCAPI(methodName: 'probeAssets', type: 'POST')]
final readonly class ProbeAssetsMethod implements ApiMethodInterface
{
    public function __construct(private AssetProbeService $probeService)
    {
    }

    public function call(Request $request): Response
    {
        $ids   = $request->getIds();
        $rows  = $this->probeService->probeMany($ids);

        $found = array_column($rows, 'id');

        $response = new Response();
        $response->setAssets($rows);
        // Report unknown ids rather than letting a short array pass for a complete answer.
        $response->setMissing(array_values(array_diff($ids, $found)));

        return $response;
    }
}
