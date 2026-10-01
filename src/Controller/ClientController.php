<?php

namespace App\Controller;

use App\Entity\Asset;
use App\Entity\File;
use App\Form\ProcessPayloadType;
use Doctrine\ORM\EntityManagerInterface;
use Survos\StateBundle\Service\EntityInterfaceDetector;
use Survos\StateBundle\Service\WorkflowHelperService;
use Survos\StateBundle\Traits\MarkingInterface;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

class ClientController extends AbstractController
{


    public function __construct(
        private EntityManagerInterface                                  $entityManager,
        private EntityInterfaceDetector $entityInterfaceDetector,
//        #[AutowireIterator('state.marking_interface')] private iterable $entityClasses,
        private WorkflowHelperService                                   $workflowHelperService,
    )
    {
    }

    #[Route('/status', name: 'app_status')]
    public function status(): array|JsonResponse
    {
        return $this->json([
            'status' => 'okay'
        ]);
    }

    #[Route('/', name: 'app_homepage')]
    #[Template('homepage.html.twig')]
    public function home(ChartBuilderInterface $chartBuilder): array
    {
        $classes = $this->entityInterfaceDetector->getEntitiesImplementing(MarkingInterface::class);

        $counts = [];
        $markingCounts = [];
        foreach ($classes as $class) {
            $repo = $this->entityManager->getRepository($class);
            $counts[$class] = $repo->count();
            $markings = $this->workflowHelperService->getCounts($class, 'marking');
            ksort($markings);
            $markingCounts[$class] = $markings;
        }

        $assetMarkings = $markingCounts[Asset::class] ?? [];
        $chart = $chartBuilder->createChart(Chart::TYPE_DOUGHNUT);
        $chart->setData([
            'labels' => array_keys($assetMarkings),
            'datasets' => [[
                'backgroundColor' => ['#206bc4', '#2fb344', '#f76707', '#ae3ec9', '#4299e1'],
                'data' => array_values($assetMarkings),
            ]],
        ]);
        $chart->setOptions([
            'plugins' => ['legend' => ['position' => 'bottom']],
            'maintainAspectRatio' => false,
        ]);

        // A handful of AI-enriched assets to showcase — same claim_caption search fixes above
        // (ClaimSearchSync) make these findable, so show off both at once.
        $featured = $this->entityManager->getRepository(Asset::class)->createQueryBuilder('a')
            ->where('a.claimCaption IS NOT NULL')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(6)
            ->getQuery()
            ->getResult();

        return [
            'assetTotal' => $counts[Asset::class] ?? 0,
            'mediaRecordTotal' => $counts[\App\Entity\MediaRecord::class] ?? null,
            'assetMarkings' => $assetMarkings,
            'markingChart' => $chart,
            'featured' => $featured,
        ];
    }


    // https://insight.symfony.com/docs/notifications/custom-webhook.html
    // https://medium.com/@skowron.dev/discovering-symfonys-secret-weapon-the-ultimate-guide-to-the-webhook-component-bae1449f4504
// https://dev.to/sensiolabs/how-to-use-the-new-symfony-maker-command-to-work-with-github-webhooks-2c8n
    #[Route('/test-webhook', name: 'app_webhook')]
    public function webhook(Request $request): Response
    {
        return new Response(json_encode($request->request->all(), JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES));
    }

}
