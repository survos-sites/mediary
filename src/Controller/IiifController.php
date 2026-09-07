<?php
declare(strict_types=1);

namespace App\Controller;

use Survos\DataContracts\Vocabulary\MediaPreset;

use App\Entity\Asset;
use App\Entity\IiifManifest;
use App\Repository\AssetRepository;
use App\Repository\IiifManifestRepository;
use App\Service\AssetRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class IiifController extends AbstractController
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly IiifManifestRepository $iiifManifestRepository,
        private readonly AssetRegistry $assetRegistry,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    #[Route('/iiif', name: 'iiif_browse', options: ['expose' => true], methods: ['GET'])]
    public function browse(): Response
    {
        $items = $this->iiifManifestRepository->findBy([], ['createdAt' => 'DESC']);

        return $this->render('iiif/browse.html.twig', [
            'items' => $items,
        ]);
    }

    #[Route('/iiif/show/{id}', name: 'iiif_show', options: ['expose' => true], methods: ['GET'])]
    public function show(string $id): Response
    {
        $manifest = $this->iiifManifestRepository->find($id);
        if (!$manifest instanceof IiifManifest) {
            throw $this->createNotFoundException(sprintf('IIIF manifest not found: %s', $id));
        }

        return $this->render('iiif/show.html.twig', [
            'manifest' => $manifest,
        ]);
    }

    /*
    #[Route('/iiif/2/{id}/info.json', name: 'iiif_image_info', methods: ['GET'])]
    public function info(string $id): JsonResponse
    {
        $asset = $this->findAsset($id);
        [$width, $height] = $this->dimensions($asset);

        $sizes = [];
        foreach (MediaPreset::PRESETS as $preset) {
            [$w, $h] = $preset['size'];
            $sizes[] = ['width' => (int) $w, 'height' => (int) $h];
        }

        return $this->json([
            '@context' => 'http://iiif.io/api/image/2/context.json',
            '@id' => $this->generateUrl('iiif_image_base', ['id' => $asset->id], UrlGeneratorInterface::ABSOLUTE_URL),
            'protocol' => 'http://iiif.io/api/image',
            'width' => $width,
            'height' => $height,
            'profile' => ['http://iiif.io/api/image/2/level0.json'],
            'tiles' => [],
            'sizes' => $sizes,
        ]);
    }
    */

    #[Route('/iiif/2/{id}/info.json', name: 'iiif_image_info', methods: ['GET'])]
    public function info(string $id): JsonResponse
    {
        $asset = $this->findAsset($id);
        [$width, $height] = $this->dimensions($asset);

        $sizes = [];
        foreach ([192, 600, 1200] as $w) {
            $sizes[] = [
                'width'  => $w,
                'height' => (int) round($w * $height / $width),
            ];
        }

        return $this->json([
            '@context' => 'http://iiif.io/api/image/2/context.json',
            '@id'      => $this->generateUrl('iiif_image_base', ['id' => $asset->id], UrlGeneratorInterface::ABSOLUTE_PATH),
            'protocol' => 'http://iiif.io/api/image',
            'width'    => (int) $width,
            'height'   => (int) $height,
            'profile'  => [
                'http://iiif.io/api/image/2/level2.json',
                [
                    'formats'   => ['jpg'],
                    'qualities' => ['default', 'gray'],
                    'supports'  => [
                        'regionByPx',
                        'regionByPct',
                        'sizeByW',
                        'sizeByH',
                        'sizeByWh',
                        'sizeByForcedWh',
                        'sizeByConfinedWh',
                    ],
                ],
            ],
            'tiles' => [
                [
                    'width'        => 256,
                    'height'       => 256,
                    'scaleFactors' => [1, 2, 4, 8, 16, 32],
                ],
            ],
            'sizes' => $sizes,
        ]);
    }

    #[Route('/iiif/2/{id}', name: 'iiif_image_base', methods: ['GET'])]
    public function base(string $id): JsonResponse
    {
        return $this->info($id);
    }

    /*
    #[Route('/iiif/2/{id}/{region}/{size}/{rotation}/{quality}.{format}', name: 'iiif_image', methods: ['GET'])]
    public function image(string $id, string $region, string $size, string $rotation, string $quality, string $format): Response
    {
        $asset = $this->findAsset($id);

        if ($format !== 'jpg' && $format !== 'jpeg') {
            throw new BadRequestHttpException('Only jpg is supported for IIIF level 0.');
        }
        if ($region !== 'full') {
            throw new BadRequestHttpException('Only full region is supported for IIIF level 0.');
        }
        if ($rotation !== '0') {
            throw new BadRequestHttpException('Only 0 rotation is supported for IIIF level 0.');
        }
        if (!in_array($quality, ['default', 'color', 'gray', 'bitonal'], true)) {
            throw new BadRequestHttpException('Unsupported quality.');
        }

        $preset = $this->presetFromIiifSize($size);
        $url = $this->assetRegistry->imgProxyUrl($asset, $preset);

        return new RedirectResponse($url, 302);
    }
    */

    #[Route('/iiif/2/{id}/{region}/{size}/{rotation}/{quality}.{format}', name: 'iiif_image', methods: ['GET'])]
    public function image(string $id, string $region, string $size, string $rotation, string $quality, string $format): Response
    {
        $asset = $this->findAsset($id);
        [$fullWidth, $fullHeight] = $this->dimensions($asset);

        if ($format !== 'jpg' && $format !== 'jpeg') {
            throw new BadRequestHttpException('Only jpg is supported.');
        }

        // ── Region ────────────────────────────────────────────────────────────
        $crop = null;
        if ($region === 'full' || $region === 'max') {
            $crop = null;
        } elseif (preg_match('/^(\d+),(\d+),(\d+),(\d+)$/', $region, $m)) {
            $crop = [(int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4]]; // x,y,w,h
        } elseif (preg_match('/^pct:([\d.]+),([\d.]+),([\d.]+),([\d.]+)$/', $region, $m)) {
            $crop = [
                (int)round((float)$m[1] / 100 * $fullWidth),
                (int)round((float)$m[2] / 100 * $fullHeight),
                (int)round((float)$m[3] / 100 * $fullWidth),
                (int)round((float)$m[4] / 100 * $fullHeight),
            ];
        } else {
            throw new BadRequestHttpException('Unsupported region: ' . $region);
        }

        // ── Size ──────────────────────────────────────────────────────────────
        $resizeW = null;
        $resizeH = null;
        $bestFit = false;

        if ($size === 'full' || $size === 'max') {
            // no resize
        } elseif (preg_match('/^(\d+),$/', $size, $m)) {
            $resizeW = (int)$m[1];
        } elseif (preg_match('/^,(\d+)$/', $size, $m)) {
            $resizeH = (int)$m[1];
        } elseif (preg_match('/^!(\d+),(\d+)$/', $size, $m)) {
            $resizeW = (int)$m[1];
            $resizeH = (int)$m[2];
            $bestFit = true;
        } elseif (preg_match('/^(\d+),(\d+)$/', $size, $m)) {
            $resizeW = (int)$m[1];
            $resizeH = (int)$m[2];
        } else {
            throw new BadRequestHttpException('Unsupported size: ' . $size);
        }

        // ── Build imgproxy URL and proxy response ──────────────────────────────
        $url = $this->assetRegistry->imgProxyUrlWithCrop(
            $asset,
            $crop,
            $resizeW,
            $resizeH,
            $bestFit,
            $quality === 'gray' ? 'grayscale' : null
        );

        $upstream = $this->httpClient->request('GET', $url);
        $status = $upstream->getStatusCode();
        if ($status >= 400) {
            throw new NotFoundHttpException(sprintf('IIIF tile upstream failed (%d): %s', $status, $url));
        }

        $headers = $upstream->getHeaders(false);
        $contentType = $headers['content-type'][0] ?? 'image/jpeg';

        return new Response(
            $upstream->getContent(),
            200,
            [
                'Content-Type' => $contentType,
                'Cache-Control' => 'public, max-age=86400',
            ],
        );
    }

    #[Route('/iiif/3/{id}/mirador', name: 'iiif_mirador', methods: ['GET'])]
    public function mirador(string $id): RedirectResponse
    {
        $manifestUrl = $this->generateUrl('iiif_manifest', ['id' => $id], UrlGeneratorInterface::ABSOLUTE_URL);
        return new RedirectResponse(
            'https://projectmirador.org/embed/?iiif-content=' . urlencode($manifestUrl),
            302,
        );
    }

    #[Route('/iiif/3/{id}/uv', name: 'iiif_uv', methods: ['GET'])]
    public function universalViewer(string $id): RedirectResponse
    {
        $manifestUrl = $this->generateUrl('iiif_manifest', ['id' => $id], UrlGeneratorInterface::ABSOLUTE_URL);
        return new RedirectResponse(
            'https://demo.universalviewer.io/uv.html?manifest=' . urlencode($manifestUrl),
            302,
        );
    }

    #[Route('/iiif/3/{id}/manifest', name: 'iiif_manifest', methods: ['GET'])]
    public function manifest(string $id): JsonResponse
    {
        $asset = $this->findAsset($id);
        [$width, $height] = $this->dimensions($asset);

        $manifestId = $this->generateUrl('iiif_manifest', ['id' => $asset->id], UrlGeneratorInterface::ABSOLUTE_PATH);
        $canvasId = $manifestId . '/canvas/p1';
        $annotationPageId = $manifestId . '/page/p1';
        $annotationId = $manifestId . '/annotation/p1-image';

        $bodyId = $this->generateUrl(
            'iiif_image',
            [
                'id' => $asset->id,
                'region' => 'full',
                'size' => 'max',
                'rotation' => '0',
                'quality' => 'default',
                'format' => 'jpg',
            ],
            UrlGeneratorInterface::ABSOLUTE_PATH
        );

        $thumbId = $this->generateUrl(
            'iiif_image',
            [
                'id' => $asset->id,
                'region' => 'full',
                'size' => '192,',
                'rotation' => '0',
                'quality' => 'default',
                'format' => 'jpg',
            ],
            UrlGeneratorInterface::ABSOLUTE_PATH
        );

        $label = $asset->sourceMeta['dcterms:title']
            ?? $asset->sourceMeta['title']
            ?? $asset->context['title']
            ?? ('Asset ' . $asset->id);

        return $this->json([
            '@context' => 'http://iiif.io/api/presentation/3/context.json',
            'id' => $manifestId,
            'type' => 'Manifest',
            'label' => ['en' => [(string) $label]],
            'thumbnail' => [[
                'id' => $thumbId,
                'type' => 'Image',
                'format' => 'image/jpeg',
                'width' => 192,
                'height' => 192,
            ]],
            'items' => [[
                'id' => $canvasId,
                'type' => 'Canvas',
                'width' => $width,
                'height' => $height,
                'items' => [[
                    'id' => $annotationPageId,
                    'type' => 'AnnotationPage',
                    'items' => [[
                        'id' => $annotationId,
                        'type' => 'Annotation',
                        'motivation' => 'painting',
                        'target' => $canvasId,
                        'body' => [
                            'id' => $bodyId,
                            'type' => 'Image',
                            'format' => 'image/jpeg',
                            'width' => $width,
                            'height' => $height,
                            'service' => [[
                                'id' => $this->generateUrl('iiif_image_base', ['id' => $asset->id], UrlGeneratorInterface::ABSOLUTE_PATH),
                                'type' => 'ImageService2',
                                'profile' => 'level0',
                            ]],
                        ],
                    ]],
                ]],
            ]],
        ]);
    }

    private function presetFromIiifSize(string $size): string
    {
        if ($size === 'full' || $size === 'max') {
            return MediaPreset::LARGE;
        }

        if (preg_match('/^(\d+),$/', $size, $m) === 1) {
            $requestedWidth = (int) $m[1];
            $candidates = [];
            foreach (MediaPreset::PRESETS as $presetName => $preset) {
                $candidates[$presetName] = (int) $preset['size'][0];
            }

            asort($candidates);
            foreach ($candidates as $presetName => $width) {
                if ($requestedWidth <= $width) {
                    return $presetName;
                }
            }
            return MediaPreset::LARGE;
        }

        throw new BadRequestHttpException('Unsupported IIIF size. Use max, full, or {w},');
    }

    private function findAsset(string $id): Asset
    {
        $asset = $this->assetRepository->find($id);
        if (!$asset instanceof Asset) {
            throw new NotFoundHttpException(sprintf('Asset not found: %s', $id));
        }

        return $asset;
    }

    private function dimensions(Asset $asset): array
    {
        $fallback = MediaPreset::PRESETS[MediaPreset::LARGE]['size'];
        $width = $asset->width ?? (int) $fallback[0];
        $height = $asset->height ?? (int) $fallback[1];

        return [$width, $height];
    }
}
