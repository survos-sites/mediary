<?php

declare(strict_types=1);

namespace App\Ai\Tool;

use App\Entity\Asset;
use App\Repository\AssetRepository;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

/**
 * Tool that lets the chat agent look up assets by URL, ID, or keyword search.
 *
 * The agent can call this to ground its answers in real collection data
 * rather than hallucinating descriptions.
 */
#[AsTool(
    name: 'asset_lookup',
    description: 'Look up an asset from the media collection by URL, 16-char hex ID, or keyword. '
        . 'Returns the asset\'s AI-extracted metadata (description, OCR text, classification, keywords, people, places). '
        . 'Call this before answering any question about a specific item in the collection.',
)]
final class AssetLookupTool
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
    ) {
    }

    /**
     * @param string $query  Asset URL, 16-char hex ID, or a keyword to search by.
     */
    public function __invoke(string $query): array
    {
        // Try exact URL match first
        $asset = $this->assetRepository->findOneByUrl($query);

        // Try hex ID
        if ($asset === null && preg_match('/^[0-9a-f]{16}$/', $query)) {
            $asset = $this->assetRepository->find($query);
        }

        // Keyword fallback: find assets whose completed task results mention the query
        if ($asset === null) {
            $asset = $this->findByKeyword($query);
        }

        if ($asset === null) {
            return [
                'found'  => false,
                'query'  => $query,
                'hint'   => 'No asset found. Try the exact URL or asset ID.',
            ];
        }

        return $this->summariseAsset($asset);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function findByKeyword(string $query): ?Asset
    {
        // Simple LIKE search on originalUrl as a lightweight fallback.
        // Replace with the Elasticsearch image index (App\Search\ImageSearch).
        $qb = $this->assetRepository->createQueryBuilder('a')
            ->where('a.originalUrl LIKE :q')
            ->setParameter('q', '%' . addcslashes($query, '%_') . '%')
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }

    private function summariseAsset(Asset $asset): array
    {
        // Index completed results by task name for easy access
        $results = [];
        foreach ($asset->aiCompleted as $entry) {
            if (!empty($entry['task']) && !empty($entry['result'])) {
                $results[$entry['task']] = $entry['result'];
            }
        }

        $summary = [
            'found'       => true,
            'id'          => $asset->id,
            'url'         => $asset->originalUrl,
            'smallUrl'    => $asset->smallUrl,
            'mime'        => $asset->mime,
            'marking'     => $asset->marking,
            'ai_queue'    => $asset->aiQueue,
            'ai_locked'   => $asset->aiLocked,
        ];

        // Attach the most useful extracted fields at the top level
        if (isset($results['classify'])) {
            $summary['type']       = $results['classify']['type'] ?? null;
            $summary['subtype']    = $results['classify']['subtype'] ?? null;
        }

        if (isset($results['context_description'])) {
            $summary['description'] = $results['context_description']['description'] ?? null;
        } elseif (isset($results['basic_description'])) {
            $summary['description'] = $results['basic_description']['description'] ?? null;
        }

        if (isset($results['extract_metadata'])) {
            $meta = $results['extract_metadata'];
            $summary['date_range']     = $meta['dateRange'] ?? null;
            $summary['people']         = $meta['people'] ?? [];
            $summary['places']         = $meta['places'] ?? [];
            $summary['subjects']       = $meta['subjects'] ?? [];
            $summary['organisations']  = $meta['organisations'] ?? [];
        }

        if (isset($results['generate_title'])) {
            $summary['title'] = $results['generate_title']['title'] ?? null;
        }

        if (isset($results['keywords'])) {
            $summary['keywords'] = $results['keywords']['keywords'] ?? [];
        }

        if (isset($results['ocr']) || isset($results['ocr_mistral'])) {
            $text = $results['ocr_mistral']['text'] ?? $results['ocr']['text'] ?? null;
            // Truncate OCR text so it doesn't flood context
            $summary['ocr_excerpt'] = $text ? mb_substr($text, 0, 800) : null;
        }

        if (isset($results['summarize'])) {
            $summary['summary'] = $results['summarize']['summary'] ?? null;
        }

        if (isset($results['people_and_places'])) {
            $pp = $results['people_and_places'];
            $summary['named_people']        = $pp['people'] ?? [];
            $summary['named_places']        = $pp['places'] ?? [];
            $summary['named_organisations'] = $pp['organisations'] ?? [];
        }

        return $summary;
    }
}
