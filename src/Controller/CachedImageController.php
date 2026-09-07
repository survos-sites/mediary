<?php

declare(strict_types=1);

namespace App\Controller;

use Survos\DataContracts\Vocabulary\MediaPreset;


use App\Service\AssetRegistry;
use App\Workflow\AssetFlow;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Survos\DataContracts\Util\MediaKeyService;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Routing\Attribute\Route;

use function base64_decode;
use function file_exists;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rename;
use function sha1;
use function sprintf;
use function strtr;

final class CachedImageController
{

    public function __construct(
        private readonly AssetRegistry $assetRegistry,
        private AsyncQueueLocator      $asyncQueueLocator,
        private MessageBusInterface    $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/media/{preset}/{encoded}', name: 'sais_cached_image', options: ['expose' => true])]
    public function renderImage(
        string $preset,
        ?string $encoded=null,
        #[MapQueryParameter] ?string $client = null,
        #[MapQueryParameter] ?bool $sync = null,
        #[MapQueryParameter] ?string $url = null,
    ): Response
    {
        if (!isset(MediaPreset::PRESETS[$preset])) {
            throw new BadRequestHttpException('Unknown image preset: ' . $preset);
        }

        $source = $encoded ? MediaKeyService::stringFromEncoded($encoded) : $url;
        if ($source === false) {
            throw new BadRequestHttpException('Invalid base64 source.');
        }

        if ($sync) {
            $this->asyncQueueLocator->sync = true;
        }


        // Ensure asset is registered
        $asset = $this->assetRegistry->ensureAsset($source, $client);
//        dump(beforeDispatch: $asset);
        // queue up download
        $this->assetRegistry->dispatch($asset);
//        dd(afterDispatch: $asset);

        $imgproxyUrl = $this->assetRegistry->imgProxyUrl($asset, MediaPreset::SMALL);
        $this->logger->info("Redirecting with image: {$source}");

        $response = new RedirectResponse($imgproxyUrl, 302);
        // Cache aggressively: imgproxy URLs are content-addressed
//        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        return $response;
    }
}
