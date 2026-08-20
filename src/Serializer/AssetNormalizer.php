<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Asset;
use App\Service\AssetRegistry;
use Survos\MediaBundle\Service\MediaUrlGenerator;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Expands Asset::$aiCompleted into clean top-level fields for Meilisearch
 * indexing and API Platform serialization.
 *
 * The entity itself stores only the raw pipeline blobs (aiQueue, aiCompleted,
 * aiLocked) plus aiDocumentType for SQL filtering. Everything else — title,
 * description, OCR text, keywords, people, etc. — is computed here at
 * normalisation time from the aiCompleted history.
 *
 * Last-written task wins for each field. If a task has been run multiple
 * times, the most recent entry is used (aiCompleted is append-only so we
 * iterate in reverse).
 */
final class AssetNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    public function __construct(
        private readonly AssetRegistry $assetRegistry,
    ) {
    }

    private const ALREADY_CALLED = 'ASSET_NORMALIZER_ALREADY_CALLED';

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Asset && !($context[self::ALREADY_CALLED] ?? false);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Asset::class => false];
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        /** @var Asset $object */
        $context[self::ALREADY_CALLED] = true;

        /** @var array<string, mixed> $data */
        $data = $this->normalizer->normalize($object, $format, $context);

        // Expand aiCompleted into clean keys — most recent run of each task wins.
        $ai = $this->expandAiCompleted($object->aiCompleted ?? []);
        $enrichment = $this->expandMediaEnrichment($object->mediaEnrichment ?? null);

        // Merge: computed AI fields go in as top-level keys.
        // They will not collide with real columns because we've dropped the flat ones.
        $data = array_merge($data, $ai, $enrichment);

        if (!isset($data['aiOcrText']) || $data['aiOcrText'] === null || $data['aiOcrText'] === '') {
            $data['aiOcrText'] = $object->localOcrText ?? null;
        }

        $signedSmall = $this->assetRegistry->imgProxyUrl($object, MediaUrlGenerator::PRESET_SMALL);
        if (is_string($signedSmall) && $signedSmall !== '') {
            $data['smallUrl'] = $signedSmall;
            $data['thumb'] = $signedSmall;
        }

        // ThumbHash for the blur placeholder that stands in until $thumb loads. Promoted
        // out of context['info'] for the same reason smallUrl is: clients (api-grid rows,
        // Meilisearch hits) render from this payload and should not have to know that
        // imgproxy's /info response is nested under `context`.
        //
        // Hex-encoded — that is imgproxy's encoding, NOT the unpadded base64 that
        // Survos\ThumbHashBundle emits. The `thumbhash` Stimulus controller takes an
        // `encoding` value for exactly this reason; see docs/local-image-analysis.md.
        $thumbHash = $object->context['info']['thumb_hash'] ?? null;
        $data['thumbHash'] = is_string($thumbHash) && $thumbHash !== '' ? $thumbHash : null;

        $sourceMeta = is_array($object->sourceMeta) ? $object->sourceMeta : [];
        $data['iiifManifest'] = $object->iiifManifestEntity?->manifestUrl ?? ($sourceMeta['iiif_manifest'] ?? null);
        $data['iiifBase'] = $object->iiifManifestEntity?->imageBase ?? ($sourceMeta['iiif_base'] ?? null);
        $data['iiifThumb'] = $object->iiifManifestEntity?->thumbnailUrl
            ?? ($sourceMeta['iiif_thumbnail_url'] ?? ($sourceMeta['thumbnail_url'] ?? null));
        $data['iiifLabel'] = $object->iiifManifestEntity?->label ?? null;
        $data['iiifSource'] = $object->iiifManifestEntity?->source ?? null;
        $data['iiifSubjects'] = $sourceMeta['iiif_subjects'] ?? [];
        $data['iiifKeywords'] = $sourceMeta['iiif_keywords'] ?? [];
        $data['mediaRecordId'] = $object->mediaRecord?->id;

        return $data;
    }

    /**
     * @param array<string,mixed>|null $mediaEnrichment
     * @return array<string,mixed>
     */
    private function expandMediaEnrichment(?array $mediaEnrichment): array
    {
        if (!is_array($mediaEnrichment) || $mediaEnrichment === []) {
            return [];
        }

        return array_filter([
            'mediaEnrichment' => $mediaEnrichment,
            'aiTitle' => $mediaEnrichment['title'] ?? null,
            'aiDescription' => $mediaEnrichment['description'] ?? null,
            'aiOcrText' => $mediaEnrichment['ocrText'] ?? null,
            'aiDocumentType' => $mediaEnrichment['documentType'] ?? null,
            'aiDocumentSubtype' => $mediaEnrichment['documentSubtype'] ?? null,
            'aiPeople' => $mediaEnrichment['people'] ?? [],
            'aiPlaces' => $mediaEnrichment['places'] ?? [],
            'aiOrganisations' => $mediaEnrichment['organisations'] ?? [],
            'aiKeywords' => $mediaEnrichment['keywords'] ?? [],
            'aiSubjects' => $mediaEnrichment['subjects'] ?? [],
            'aiDateRange' => $mediaEnrichment['dateRange'] ?? null,
            'aiSummary' => $mediaEnrichment['summary'] ?? ($mediaEnrichment['denseSummary'] ?? null),
        ], static fn ($value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * Collapse the aiCompleted log into a single flat projection.
     *
     * @param list<array{task: string, at: string, result: array}> $completed
     * @return array<string, mixed>
     */
    private function expandAiCompleted(array $completed): array
    {
        // Index by task — last entry wins (append-only log, newest = last)
        $byTask = [];
        foreach ($completed as $entry) {
            $task = $entry['task'] ?? null;
            if ($task && empty($entry['result']['failed']) && empty($entry['result']['skipped'])) {
                $byTask[$task] = $entry['result'];
            }
        }

        $out = [];

        // ── Title ─────────────────────────────────────────────────────────────
        if ($t = ($byTask['generate_title']['title'] ?? null)) {
            $out['aiTitle'] = $t;
        }

        // ── Description: context wins over basic ──────────────────────────────
        $out['aiDescription'] = $byTask['context_description']['description']
            ?? $byTask['basic_description']['description']
            ?? null;

        // ── OCR text: mistral wins over plain OCR, transcription as fallback ──
        $out['aiOcrText'] = $byTask['ocr_mistral']['text']
            ?? $byTask['ocr']['text']
            ?? $byTask['transcribe_handwriting']['text']
            ?? null;

        // ── Classification ────────────────────────────────────────────────────
        // aiDocumentType is also stored as a real column for SQL WHERE, but we
        // emit it here too so the normalised doc is self-contained.
        $out['aiDocumentType']    = $byTask['classify']['type']    ?? null;
        $out['aiDocumentSubtype'] = $byTask['classify']['subtype'] ?? null;

        // ── People, places, organisations ─────────────────────────────────────
        // people_and_places is authoritative; extract_metadata fills in if not run.
        $out['aiPeople'] = $byTask['people_and_places']['people']
            ?? $byTask['extract_metadata']['people']
            ?? [];

        $out['aiPlaces'] = $byTask['people_and_places']['places']
            ?? $byTask['extract_metadata']['places']
            ?? [];

        $out['aiOrganisations'] = array_values(array_unique(array_merge(
            $byTask['people_and_places']['organisations'] ?? [],
            $byTask['extract_metadata']['organisations'] ?? [],
        )));

        // ── Keywords ─────────────────────────────────────────────────────────
        $out['aiKeywords'] = $byTask['keywords']['keywords'] ?? [];

        // ── Date range ────────────────────────────────────────────────────────
        $out['aiDateRange'] = $byTask['extract_metadata']['dateRange'] ?? null;

        // ── Subjects ─────────────────────────────────────────────────────────
        $out['aiSubjects'] = $byTask['extract_metadata']['subjects'] ?? [];

        // ── Summary ───────────────────────────────────────────────────────────
        $out['aiSummary'] = $byTask['summarize']['summary'] ?? null;

        // ── Token totals across all tasks ─────────────────────────────────────
        $totalTokens = 0;
        foreach ($completed as $entry) {
            $totalTokens += $entry['result']['_tokens']['total'] ?? 0;
        }
        $out['aiTokensTotal'] = $totalTokens ?: null;

        // ── Safety ────────────────────────────────────────────────────────────
        $out['aiSafety'] = $byTask['keywords']['safety'] ?? null;

        // Drop nulls to keep the Meilisearch document lean.
        return array_filter($out, fn($v) => $v !== null && $v !== [] && $v !== '');
    }
}
