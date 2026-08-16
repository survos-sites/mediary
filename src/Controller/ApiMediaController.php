<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\Asset;
use App\Service\AssetProbeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Debug-friendly media resolution/probe:
 * - GET  /fetch/media/{id}             (single asset probe)
 * - GET  /api/media/by-ids?id=a,b,c   (also supports multiple id=)
 * - POST /api/media/by-ids            (JSON: {"ids": ["a","b","c"]})
 */
final class ApiMediaController extends AbstractController
{
    public function __construct(
        public readonly EntityManagerInterface $em,
        private readonly AssetProbeService $probeService,
    ) {}

    #[Route('/fetch/media/{id}', name: 'api_media_probe_single', methods: ['GET'])]
    public function probeSingle(string $id): JsonResponse
    {
        /** @var Asset|null $asset */
        $asset = $this->em->getRepository(Asset::class)->find($id);
        if (!$asset) {
            throw new NotFoundHttpException(sprintf('Asset not found: %s', $id));
        }

        return $this->json($this->probeService->probe($asset));
    }

    #[Route('/fetch/media/by-ids', name: 'api_media_by_ids_get', methods: ['GET'])]
    public function byIdsGet(Request $request,
        #[MapQueryParameter] ?array $ids=null,
        #[MapQueryParameter] ?string $id=null
    ): JsonResponse
    {
        $ids ??=  explode(',', $id);
//        dd($ids);
//        $ids = array_values(array_unique($ids));

        return $this->resolveToJson($ids);
    }

    #[Route('/fetch/media/by-ids', name: 'api_media_by_ids_post', methods: ['POST'])]
    public function byIdsPost(Request $request): JsonResponse
    {
        $payload = $request->getContent() === '' ? [] : (json_decode($request->getContent(), true) ?? []);
        $ids = array_values(array_filter((array) ($payload['ids'] ?? []), static fn($v) => $v !== null && $v !== ''));
        return $this->resolveToJson($ids);
    }

    /**
     * @param string[] $ids
     */
    private function resolveToJson(array $ids): JsonResponse
    {
        // Identifiers are Asset ids (16-hex). Shaping lives in AssetProbeService so this
        // and the JSON-RPC probeAssets method always return the same payload.
        return $this->json($this->probeService->probeMany(array_values($ids)));
    }
}
