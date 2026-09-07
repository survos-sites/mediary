<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Asset;
use Survos\DataContracts\Vocabulary\MediaSyncKeys;
use Survos\DataContracts\Workflow\AudioSubjectInterface;
use Survos\DataContracts\Workflow\ContextSubjectInterface;
use Survos\DataContracts\Workflow\ImageSubjectInterface;
use Survos\DataContracts\Workflow\WorkflowSubjectInterface;

/**
 * Adapts a mediary {@see Asset} to the ai-workflow-bundle subject interfaces so
 * its TaskRegistry tasks (observe, ocr_mistral, …) can run directly against an
 * Asset — without the removed ai-pipeline-bundle handler layer.
 */
final class AssetSubject implements WorkflowSubjectInterface, ImageSubjectInterface, AudioSubjectInterface, ContextSubjectInterface
{
    /** @param array<string,mixed> $context runtime hints merged over the asset's own context */
    public function __construct(
        private readonly Asset $asset,
        private readonly array $context = [],
    ) {
    }

    public function getWorkflowSubjectId(): string
    {
        // The universal image id: the asset id IS xxh3(canonical image URL) from our one-and-only id
        // generator (MediaIdentity::idFromOriginalUrl). The app re-derives the same id from each record's
        // image URL to fetch/record claims, so observe (mediary) and analyze/folio (app) share identity.
        return $this->asset->id;
    }

    public function getWorkflowSubjectType(): string
    {
        // Claims key by the Asset class (record-centric via the universal image id), not a brittle
        // provider prefix. The app fetches them with: WHERE subjectType = Asset::class AND scope = <dataset>.
        return Asset::class;
    }

    public function getWorkflowScope(): ?string
    {
        // A caller that knows the dataset (e.g. ai/from-url?scope=nara/coll_dde-1200) scopes the
        // claims to it, so `claims:fetch <dataset>` can pull them into that dataset's vault. Bare
        // one-off calls fall back to 'mediary' (the ambient asset-cache scope).
        $sourceMeta = $this->asset->sourceMeta ?? [];
        $scope = $this->context['scope']
            ?? $this->context[MediaSyncKeys::DATASET]
            ?? ($sourceMeta[MediaSyncKeys::DATASET] ?? null);

        return is_string($scope) && $scope !== '' ? $scope : 'mediary';
    }

    public function isWorkflowLocked(): bool
    {
        return $this->asset->aiLocked;
    }

    public function setWorkflowLocked(bool $locked): void
    {
        $this->asset->aiLocked = $locked;
    }

    public function getWorkflowImageUrl(): ?string
    {
        return $this->asset->originalUrl;
    }

    /**
     * Same underlying field as getWorkflowImageUrl() — Asset has no separate audio-url column,
     * originalUrl is whatever URL the caller registered the asset under (an s3://.../*.m4a for a
     * YouTube-transcription asset). Kept as its own accessor rather than reusing the image one so
     * an audio-only task (transcribe_audio) doesn't read a method named for the wrong medium.
     */
    public function getWorkflowAudioUrl(): ?string
    {
        return $this->asset->originalUrl;
    }

    /** @return array<string,mixed> */
    public function getWorkflowContext(): array
    {
        return array_merge($this->asset->context ?? [], $this->context);
    }
}
