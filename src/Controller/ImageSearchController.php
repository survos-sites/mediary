<?php

declare(strict_types=1);

namespace App\Controller;

use App\Search\ImageSearch;
use Survos\SearchBundle\Search\SearchProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The pan-image search page: InstantSearch widgets over the Elasticsearch image index. */
final class ImageSearchController extends AbstractController
{
    #[Route('/images', name: 'app_image_search', methods: ['GET'])]
    public function index(SearchProvider $provider): Response
    {
        $search = $provider->getSearch(ImageSearch::NAME)->create();
        $facets = [];
        $ranges = [];
        foreach ($search->getFacets() as $facet) {
            if ($facet->getProperty() === 'faceCount') {
                $ranges[$facet->getProperty()] = $facet->getLabel();
            } else {
                $facets[$facet->getProperty()] = $facet->getLabel();
            }
        }
        $sorts = array_map(static fn ($sort) => ['value' => ImageSearch::NAME.'::'.$sort->getKey(), 'label' => $sort->getLabel()], $search->getAvailableSorts());

        return $this->render('search/images.html.twig', [
            'name' => ImageSearch::NAME,
            'facets' => $facets,
            'ranges' => $ranges,
            'sorts' => $sorts,
        ]);
    }

    #[Route('/search-template/image', name: 'app_image_search_template', methods: ['GET'])]
    public function template(): Response
    {
        // Twig source, rendered per hit in the browser by twig-browser.
        return new Response(
            file_get_contents($this->getParameter('kernel.project_dir').'/templates/search/image.browser.twig'),
            200,
            ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=300'],
        );
    }
}
