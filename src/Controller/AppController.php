<?php

namespace App\Controller;

use App\Entity\Asset;
use App\Entity\Media;
use App\Repository\AssetRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use Symfony\Component\Serializer\SerializerInterface;

final class AppController extends AbstractController
{

    public function __construct(
        private SerializerInterface $serializer,
        private AssetRepository $assetRepository,
    )
    {
    }

    #[Route('/health', name: 'app_health')]
    public function health(): Response
    {
        return new Response('OK', 200);
    }

    // Admin-only: the template extends EasyAdmin's layout, which has no context outside a dashboard.
    #[AdminRoute('/stats', name: 'stats')]
    public function index(): Response
    {
        return $this->render('app/index.html.twig', [
            'recent' => $this->assetRepository->findBy([], ['createdAt' => 'DESC'], 10),
            'controller_name' => 'AppController',
        ]);
    }

    #[AdminRoute('/custom/asset/{id}', name: 'custom_asset')]
    public function asset(Asset $asset): Response
    {
        return $this->render('app/asset.html.twig', [
            'asset' => $asset,
        ]);
    }

    #[Route('/browse-assets', name: 'app_browse_assets')]
    public function browseAssets(): Response
    {
        return $this->redirectToRoute('app_image_search');
    }

    //create a test route
    #[Route('/test', name: 'app_test')]
    public function test(#[Autowire('%env(S3_ENDPOINT)%')] string $apiKey): Response
    {
        //return a json response with the api key
        return $this->json([
            'apiKey' => $apiKey,
        ]);
    }

    //create webhook call test  for https://sais.wip/webhook/sais-hook
    #[Route('/webhook-test', name: 'app_webhook_test')]
    public function webhookTest(): Response
    {
        //temp : return a simple response for test flow
        return $this->json([
            'status' => 'success',
            'message' => 'Webhook test successful',
        ]);

        // Send a test webhook call to the sais-hook endpoint using Symfony HttpClient and local proxy
        $webhookUrl = 'https://sais.wip/webhook/sais-hook';

        //test section , need to build a media object using deserializer
        /*return $this->serializer->deserialize(
            $request->getContent(),
            GithubPushPayload::class,
            'json'
        );*/

        $mediaArrayPayload = [
            'name' => 'Test Media',
            'url' => 'https://example.com/media/test.mp4',
            'type' => 'video/mp4',
            'size' => 123456,
            'root' => 'https://example.com/media/',
            'createdAt' => (new \DateTimeImmutable())->format(\DateTime::ATOM),
            'updatedAt' => (new \DateTimeImmutable())->format(\DateTime::ATOM),
        ];

        $data = [
            'name' => 'Test Webhook',
            'id' => '12345',
            'timestamp' => (new \DateTime())->format(\DateTime::ATOM),
            'media' => json_encode($mediaArrayPayload),
        ];

        $client = \Symfony\Component\HttpClient\HttpClient::create([
            'proxy' => 'http://127.0.0.1:7080', // Symfony local proxy default port
            'verify_peer' => false,
            'verify_host' => false,
        ]);

        try {
            $response = $client->request('POST', $webhookUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Authentication-Token' => $_ENV['SAIS_HOOK_SECRET'],
                ],
                'json' => $data,
            ]);

            //dump($response->getContent(false));
            //dump($response->getHeaders(false));
            //dd($response->getStatusCode(), $response->getHeaders(false), $response->getContent(false));

            $content = $response->toArray(false);
            return $this->json([
                'status' => 'success',
                'message' => 'Webhook call successful',
                'response' => $content,
            ], Response::HTTP_OK);
        } catch (\Exception $e) {
            return $this->json([
                'status' => 'error',
                'message' => 'Webhook call failed',
                'error' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
