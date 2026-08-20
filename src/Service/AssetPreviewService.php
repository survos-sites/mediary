<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;
use Doctrine\ORM\EntityManagerInterface;
use Jenssegers\ImageHash\ImageHash;
use Jenssegers\ImageHash\Implementations\PerceptualHash;
use Psr\Log\LoggerInterface;
use Survos\ThumbHashBundle\Service\ThumbHashService;
use Survos\ThumbHashBundle\Service\Thumbhash;
use RuntimeException;

/**
 * Builds image variants (LiipImagine filters) and performs analysis (blurhash/thumbhash, pHash)
 * off the cached thumbnail file, in one place. Safe to call from workflow steps or controllers
 * as long as the original/source image is available to Liip’s data loaders.
 *
 * Typical usage:
 *   $svc->processPresets($asset, ['/uploads/originals/foo.jpg'], ['small','medium']);
 *
 * Notes:
 * - $sourceUrlPath MUST be a path that LiipImagine understands (web path, not filesystem),
 *   e.g. "/uploads/originals/foo.jpg". We then resolve the cached file under public/.
 * - This service does not flush() — the caller decides transaction boundaries.
 */
final class AssetPreviewService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
        private readonly ThumbHashService $thumbHashService,
        private readonly string $publicDir = __DIR__ . '/../../public',
    ) {}

    /**
     * Process multiple presets against a single source URL path.
     *
     * @param Asset          $asset
     * @param string         $sourceUrlPath Web path Liip can load (e.g. "/uploads/originals/foo.jpg")
     * @param list<string>   $presets       e.g. ['small','medium']
     * @return array<string,array{url:string,path:string,width:int,height:int,bytes:int}>
     */
    public function processPresets(Asset $asset, string $sourceUrlPath, array $presets): array
    {
        $results = [];
        foreach ($presets as $preset) {
            try {
                $results[$preset] = $this->processSingle($asset, $sourceUrlPath, $preset);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Preset "%s" failed for %s: %s',
                    $preset, $asset->id, $e->getMessage()));
            }
        }
        return $results;
    }

    /**
     * Build a single LiipImagine variant and run analyses that depend on the thumbnail file.
     *
     * @param Asset  $asset
     * @param string $sourceUrlPath Web path Liip can load (e.g. "/uploads/originals/foo.jpg")
     * @param string $preset        Liip filter name, e.g. "small" or "medium"
     * @return array{url:string,path:string,width:int,height:int,bytes:int}
     */
    public function processSingle(Asset $asset, string $sourceUrlPath, string $preset): array
    {

        // Resolve & build the cached variant; this triggers generation if missing
        $this->logger->debug(sprintf('LiipImagine: %s => %s', $sourceUrlPath, $preset));


        // @todo: get the thumbnail image from imgProxy and use THAT for the analysis
        $resolveUrl = '';

        $this->logger->debug('Liip variant resolved', [
            'assetId' => $asset->id,
            'preset' => $preset,
            'resolveUrl' => $resolveUrl
        ]);


        $cachedPath = $this->publicDir . (string) parse_url($cachedUrl, PHP_URL_PATH);
        if (!is_file($cachedPath)) {
            // Some setups prefer PathHelper conversion:
            // $cachedPath = PathHelper::urlPathToFilePath($cachedUrl);
            throw new \RuntimeException("Cached variant not found at {$cachedPath}");
        }

        // Read & inspect the cached file
        $bytes = (int) filesize($cachedPath);
        $content = file_get_contents($cachedPath);
        if ($content === false) {
            throw new \RuntimeException("Failed reading cached variant: {$cachedPath}");
        }

        $img = new \Imagick();
        $img->readImageBlob($content);
        $w = $img->getImageWidth();
        $h = $img->getImageHeight();

        // Opportunistically set dimensions on Asset if unknown
        if ($asset->width === null || $asset->height === null) {
            $asset->width = $w;
            $asset->height = $h;
        }

        // Do analyses that depend on the thumbnail file
        $this->maybeComputeThumbhash($asset, $preset, $content, $w, $h);
        $this->maybeComputePhash($asset, $preset, $cachedPath);

        return [
            'url'    => $cachedUrl,
            'path'   => $cachedPath,
            'width'  => $w,
            'height' => $h,
            'bytes'  => $bytes,
        ];
    }

    /**
     * @return array{0:int,1:int,2:list<int|float>}
     */
    public function resizeForThumbHashFromUrl(string $imageUrl, int $size = 100): array
    {
        $image = new \Imagick();
        $image->readImage($imageUrl);
        $image->thumbnailImage($size, $size, true);

        $width = $image->getImageWidth();
        $height = $image->getImageHeight();

        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $pixel = $image->getImagePixelColor($x, $y);
                $colors = $pixel->getColor(2);
                $pixels[] = $colors['r'];
                $pixels[] = $colors['g'];
                $pixels[] = $colors['b'];
                $pixels[] = $colors['a'];
            }
        }

        return [$width, $height, $pixels];
    }

    public function maybeComputeThumbhash(Asset $asset, string $preset, string $content): void
    {
        // Convention: compute ThumbHash on the "small" preset
        if ($preset !== 'small') {
            return;
        }

        // Extract pixels in RGBA and build ThumbHash
        [$tw, $th, $pixels] = $this->thumbHashService->extract_size_and_pixels_with_imagick($content);
//        if (!$tw || !$th) {
//            // Fallback to provided (w,h) if extractor fails unexpectedly
//            $tw = $w; $th = $h;
//        }
        $hash = Thumbhash::RGBAToHash($tw, $th, $pixels, 192, 192);
        $key  = Thumbhash::convertHashToString($hash);

        // Store on Asset context or analysis bucket; keeping it simple here:
        $asset->context ??= [];
        $asset->context['thumbhash'] = $key;
    }

    /**
     * Colour palette extraction used to live here too (league/color-extractor +
     * ColorAnalysisService). imgproxy Pro's /info now returns `average` and
     * `dominant_colors`, so the local step was removed — see
     * docs/local-image-analysis.md for the recipe if it ever needs rebuilding.
     */
    public function maybeComputePhash(Asset $asset, string $preset, string $cachedPath): void
    {
        $sourcePath = $cachedPath;
        $downloadTempPath = null;

        if (preg_match('#^https?://#', $cachedPath) === 1) {
            try {
                $bytes = file_get_contents($cachedPath);
                if ($bytes !== false) {
                    $downloadTempPath = tempnam(sys_get_temp_dir(), 'asset_preview_');
                    if ($downloadTempPath !== false) {
                        file_put_contents($downloadTempPath, $bytes);
                        $sourcePath = $downloadTempPath;
                    }
                }
            } catch (\Throwable) {
            }
        }

        $analysisTempPath = $this->createAnalysisSizedImage($sourcePath, 512);

        try {
            $hasher = new ImageHash(new PerceptualHash()); // 64-bit pHash
            $hash   = $hasher->hash($analysisTempPath ?? $sourcePath);
            $asset->context ??= [];
            $asset->context['phash'] = (string) $hash; // hex string
        } catch (\Throwable $e) {
            // Non-fatal
        }

        if ($analysisTempPath && is_file($analysisTempPath)) {
            unlink($analysisTempPath);
        }

        if ($downloadTempPath && is_file($downloadTempPath)) {
            unlink($downloadTempPath);
        }
    }

    private function createAnalysisSizedImage(string $sourcePath, int $maxSide): ?string
    {
        if (!is_file($sourcePath) || $maxSide < 32) {
            return null;
        }

        try {
            $image = new \Imagick($sourcePath);
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();

            if ($width <= $maxSide && $height <= $maxSide) {
                $image->clear();
                return null;
            }

            $image->thumbnailImage($maxSide, $maxSide, true);
            $tmpPath = tempnam(sys_get_temp_dir(), 'asset_palette_');
            if ($tmpPath === false) {
                throw new RuntimeException('Failed to create temp file for palette analysis');
            }

            $image->writeImage($tmpPath);
            $image->clear();

            return $tmpPath;
        } catch (\Throwable) {
            return null;
        }
    }
}
