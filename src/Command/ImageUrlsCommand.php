<?php
declare(strict_types=1);

namespace App\Command;

use App\Repository\AssetRepository;
use App\Service\AssetRegistry;
use App\Service\ImagorUrlBuilder;
use Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Side-by-side imgproxy vs imagor URLs for one asset — the quickest way to
 * compare the two renderers while both run (IMAGE_BACKEND picks the live one).
 */
#[AsCommand('app:image-urls', 'Show imgproxy and imagor URLs for an asset, per preset.')]
final class ImageUrlsCommand
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly AssetRegistry $assetRegistry,
        private readonly ImgproxyUrlBuilder $imgproxy,
        private readonly ImagorUrlBuilder $imagor,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Asset id')]
        string $id,
        #[Option('Comma-separated presets')]
        string $presets = 'tiny,thumb,display,archive',
    ): int {
        $asset = $this->assetRepository->find($id);
        if (null === $asset) {
            $io->error("No asset $id");

            return Command::FAILURE;
        }

        $live = $this->assetRegistry->imgProxyDebug($asset, 'thumb');
        $source = (string) $live['source_url'];
        $io->definitionList(
            ['source' => $source],
            ['source type' => $live['source']],
            ['live backend (thumb)' => $live['backend']],
        );

        $rows = [];
        foreach (explode(',', $presets) as $preset) {
            $preset = trim($preset);
            $rows[] = [$preset, 'imgproxy', $this->imgproxy->hasPreset($preset) ? $this->imgproxy->resizePreset($source, $preset) : '—'];
            $rows[] = ['', 'imagor', $this->imagor->isConfigured() && $this->imagor->supports($source) && $this->imagor->hasPreset($preset)
                ? $this->imagor->preset($source, $preset)
                : '— (not configured, or source not readable by imagor)'];
        }
        $io->table(['preset', 'backend', 'url'], $rows);

        return Command::SUCCESS;
    }
}
