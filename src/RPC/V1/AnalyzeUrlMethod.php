<?php

declare(strict_types=1);

namespace App\RPC\V1;

use App\RPC\V1\AnalyzeUrl\Request;
use App\RPC\V1\AnalyzeUrl\Response;
use App\Service\AssetRegistry;
use OV\JsonRPCAPIBundle\Core\Annotation\JsonRPCAPI;
use OV\JsonRPCAPIBundle\Core\ApiMethodInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Analyse an image by URL, over JSON-RPC.
 *
 * Replaces GET/POST /media/ai/from-url for machine callers. That endpoint sits behind the
 * session firewall, so an edge station or a hub calling it from the open internet gets a
 * 302 to /login -- which is what blocked production enrichment and narration transcription
 * entirely. There is no LAN to fall back on: a scanning station lives at a client site and
 * scanstation.ai lives on the internet, and they are only ever on the same connection by
 * accident.
 *
 * Self-authenticating, the same shape as the claim-store API: PUBLIC_ACCESS to the firewall
 * only, with a token checked here. An unset MEDIARY_API_TOKEN refuses every call rather than
 * opening a hole -- an unconfigured deploy fails closed.
 *
 *   curl -X POST https://mediary.survos.com/api/v1 \
 *     -H 'Content-Type: application/json' \
 *     -d '{"jsonrpc":"2.0","method":"analyzeUrl","id":"1","params":{
 *           "url":"https://station.example/captures/1/file",
 *           "task":"observe","token":"..."}}'
 *
 * The URL is handed to the model, which fetches it itself -- so it must be reachable from
 * the public internet. A station publishes its captures through its own tunnel precisely so
 * it can supply one without uploading anything first.
 */
#[JsonRPCAPI(methodName: 'analyzeUrl', type: 'POST')]
final readonly class AnalyzeUrlMethod implements ApiMethodInterface
{
    public function __construct(
        private AssetRegistry $assetRegistry,
        private \App\Ai\AssetAiExecutor $executor,
        #[Autowire('%env(default::MEDIARY_API_TOKEN)%')]
        private ?string $apiToken = null,
    ) {
    }

    public function call(Request $request): Response
    {
        $expected = trim((string) $this->apiToken);
        if ($expected === '') {
            // Fail closed: a deploy that forgot the token must refuse callers, not accept
            // everyone. Same rule the claim-store endpoints follow.
            throw new \RuntimeException('MEDIARY_API_TOKEN is not configured; refusing all analyzeUrl calls.');
        }

        if (!hash_equals($expected, $request->getToken())) {
            throw new \RuntimeException('Invalid token.');
        }

        $url = $request->getUrl();
        if ($url === '') {
            throw new \InvalidArgumentException('url is required.');
        }

        $task = $request->getTask();

        $asset = $this->assetRegistry->ensureAsset($url, null, flush: true);
        $outcome = $this->executor->run($asset, $task, [], $request->isForce());

        if (!($outcome['ok'] ?? false)) {
            throw new \RuntimeException(sprintf('Task %s: %s', $task, $outcome['reason'] ?? 'failed'));
        }

        $response = new Response();
        $response->setId($asset->id);
        $response->setUrl($url);
        $response->setTask($task);
        $response->setCached((bool) ($outcome['cached'] ?? false));
        $response->setResult(\is_array($outcome['response'] ?? null) ? $outcome['response'] : []);

        return $response;
    }
}
