<?php

declare(strict_types=1);

namespace App\Command;

use Survos\DataContracts\Vocabulary\MediaPreset;

use App\Controller\CachedImageController;
use Survos\DataContracts\Util\MediaKeyService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\RedirectResponse;

#[AsCommand(
    name: 'media:probe',
    description: 'Trigger CachedImageController rendering for a single URL and preset'
)]
final class MediaProbeCommand
{
    public function __construct(
        private readonly CachedImageController $cachedImageController,
    ) {}

    public function __invoke(
        SymfonyStyle $io,

        #[Argument(description: 'Source media URL')]
        ?string $url = null,

        #[Argument('Image preset (must exist in MediaPreset::PRESETS)')]
        string $preset = MediaPreset::SMALL,

        #[Option('Client identifier (optional)')]
        ?string $client = null,

        #[Option('Force synchronous workflow dispatch')]
        ?bool $sync = null,
    ): int {
        if ($url === null) {
            $text = sprintf('SAIS-%s', bin2hex(random_bytes(3)));
            $url = sprintf('https://dummyimage.com/300x200/000/fff.png&text=%s', $text);
        }

        $encoded = MediaKeyService::keyFromString($url);

        $response = $this->cachedImageController->renderImage(
            preset: $preset,
            encoded: $encoded,
            client: $client,
            sync: $sync,
        );

        $io->definitionList(
            ['Preset' => $preset],
            ['URL' => $url],
            ['Encoded' => $encoded],
            ['Client' => $client ?? '(none)'],
            ['Sync' => $sync ? 'true' : 'false'],
            ['HTTP status' => $response->getStatusCode()],
        );

        if ($response instanceof RedirectResponse) {
            $io->text($response->getTargetUrl());
        }

        return Command::SUCCESS;
    }
}
