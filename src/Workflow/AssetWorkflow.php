<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Ai\AssetAiTask;
use App\Message\WarmImgproxyCacheMessage;
use Survos\MediaBundle\Dto\MediaEnrichment;
use App\Service\AssetNotifier;
use App\Service\AssetRegistry;
use App\Service\ClaimSearchSync;
use App\Service\EdgeAnalysisService;
use App\Service\IiifManifestService;
use \RuntimeException as RuntimeException;
use App\Entity\Asset;
use App\Repository\AssetRepository;
use App\Repository\UserRepository;
use App\Service\ArchiveService;
use App\Service\AiToolsObserveService;
use App\Service\AiToolsOcrService;
use App\Service\AtomicFileWriter;
use App\Service\AssetPreviewService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use Psr\Log\LoggerInterface;
use App\Ai\AssetAiExecutor;
use Survos\AiPipelineBundle\Task\AiTaskInterface;
use Survos\ClaimsBundle\Service\ClaimIngestor;
use Survos\ClaimsBundle\Service\RawClaim;
use Survos\ClaimsBundle\Service\RunMeta;
use Survos\MediaBundle\Service\MediaKeyService;
use Survos\MediaBundle\Service\MediaUrlGenerator;
use Survos\ImgproxyBundle\Service\ImgproxyUrlBuilder;
use Symfony\Component\HttpClient\Response\StreamWrapper;
use App\Util\ImageProbe;
use Survos\StateBundle\Attribute\Workflow;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Survos\StorageBundle\Service\StorageService;
use Survos\ThumbHashBundle\Service\Thumbhash;
use Survos\ThumbHashBundle\Service\ThumbHashService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\EventDispatcher\Event;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Survos\StateBundle\Exception\UnrecoverableMessageException;
use Twig\Environment as TwigEnvironment;
use Survos\GoogleSheetsBundle\Service\GoogleDriveService;
use App\Service\OcrService;
use App\Workflow\AssetFlow as WF;

//#[Workflow(name: WF::WORKFLOW_NAME, supports: [Asset::class])]
class AssetWorkflow
{
    const THUMBHASH_PRESET = 'thumb';
    private const CANONICAL_MAX_EDGE = 3000;
    private const CANONICAL_WEBP_QUALITY = 82;
    private ?float $sourceCooldownUntil = null;
    /** @var array<string, AiTaskInterface> */
    private array $aiTaskHandlers = [];

    public function __construct(
        private readonly AssetAiExecutor $executor,
        private MediaUrlGenerator $mediaUrlGenerator,
        private readonly ArchiveService $archiveService,
        private ThumbHashService $thumbHashService,
        private readonly AtomicFileWriter $atomicFileWriter,
        private AssetPreviewService $assetPreviewService,
        private MessageBusInterface          $messageBus,
        private EntityManagerInterface                          $em,
        private AssetRepository                                 $assetRepo,
        #[Target('local.storage')] private readonly FilesystemOperator                     $localStorage,
        private readonly LoggerInterface                        $logger,
        private readonly HttpClientInterface                    $httpClient,
        private UserRepository                                  $userRepository,
        #[Target(WF::WORKFLOW_NAME)] private WorkflowInterface $assetWorkflow,
        private SerializerInterface                            $serializer,
        private NormalizerInterface                            $normalizer,
        #[Autowire('%env(int:SERVICE_529_COOLDOWN_MS)%')]
        private readonly int                                   $source529CooldownMs,
        #[Autowire('%env(int:SERVICE_HTTP_TIMEOUT_SECONDS)%')]
        private readonly int                                   $serviceHttpTimeoutSeconds,
        #[Autowire('%env(bool:AI_PAID_TOOLS_ENABLED)%')]
        private readonly bool                                  $paidAiToolsEnabled,
        #[Autowire('%kernel.project_dir%/public/temp')]
        private string                                         $tempDir,
        #[Autowire('%env(default:media_canonical_dir_default:MEDIA_CANONICAL_DIR)%')]
        private readonly string                                $canonicalDir,
        private readonly EntityManagerInterface                $entityManager,
        private readonly AsyncQueueLocator                     $asyncQueueLocator,
        private readonly StorageService $storageService,
        private readonly AssetRegistry $assetRegistry,
        private readonly ImgproxyUrlBuilder $imgproxyUrlBuilder,
        private readonly IiifManifestService $iiifManifestService,
        private readonly EdgeAnalysisService $edgeAnalysisService,
        private readonly OcrService $ocrService,
        private readonly AiToolsOcrService $aiToolsOcrService,
        private readonly AiToolsObserveService $aiToolsObserveService,
        private readonly ClaimIngestor $claimIngestor,
        private readonly ClaimSearchSync $claimSearchSync,
        private readonly AssetNotifier $assetNotifier,
        private readonly TwigEnvironment $twig,
        #[Target('archive.storage')]  private readonly ?FilesystemOperator $archiveStorage = null,
        private ?GoogleDriveService $driveService = null,
        iterable $taskServices = [],
    ) {
        foreach ($taskServices as $task) {
            if ($task instanceof AiTaskInterface) {
                $this->aiTaskHandlers[$task->getTask()] = $task;
            }
        }
    }

    /** @return Asset */
    private function getAsset(Event $event): Asset
    {
        //        assert($subject instanceof Asset, 'Expected Asset entity, got ' . get_class($subject));
        return $event->getSubject();
    }

    private function uploadToArchiveFromPath(Asset $asset, string $localPath): void
    {
        if (!is_file($localPath)) {
            throw new RuntimeException(sprintf(
                'Local payload missing for archive (asset %s, path "%s").',
                $asset->id,
                $localPath
            ));
        }

        $localSize = filesize($localPath);
        if ($localSize === 0) {
            throw new RuntimeException(sprintf(
                'Refusing to archive zero-byte payload (asset %s).',
                $asset->id
            ));
        }

        // Derive extension from detected MIME
        $extension = MediaKeyService::extensionFromMime($asset->mime) ?? ($asset->ext ?: 'bin');

        // Prefer content-addressed path when available, fallback to URL-based path.
        $sha256 = $asset->context['sha256'] ?? null;
        if (is_string($sha256) && preg_match('/^[a-f0-9]{64}$/', $sha256) === 1) {
            $path = sprintf('orig/%s/%s/%s.%s', substr($sha256, 0, 2), substr($sha256, 2, 2), $sha256, $extension);
        } else {
            $path = MediaKeyService::archivePathFromUrl(
                $asset->originalUrl,
                $extension
            );
        }

        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Failed to open local payload for streaming.');
        }

        try {
            // Idempotency: if object already exists, verify integrity
            if ($this->archiveStorage->fileExists($path)) {
                $remoteSize = $this->archiveStorage->fileSize($path);
                if ($remoteSize !== $localSize) {
                    throw new RuntimeException(sprintf(
                        'Archive object exists with mismatched size (remote=%d, local=%d).',
                        $remoteSize,
                        $localSize
                    ));
                }
            } else {
                // Stream upload (constant memory)
                $this->archiveStorage->writeStream(
                    $path,
                    $stream,
                    ['visibility' => Visibility::PUBLIC]
                );
            }
        } finally {
            fclose($stream);
        }

        // Persist archive metadata
        $asset->storageKey = $path;
        $asset->storageBackend = 'archive';
        $asset->archiveUrl = $this->assetRegistry->s3Url($asset);
        $asset->smallUrl = $this->assetRegistry->imgProxyUrl($asset, MediaUrlGenerator::PRESET_SMALL);
    }

    public function ingestLocalFile(Asset $asset, string $localPath): void
    {
        if (!is_file($localPath)) {
            throw new RuntimeException(sprintf('Local file not found: %s', $localPath));
        }

        $asset->statusCode = 200;
        $this->processLocalFile($localPath, $asset);
        $this->applyEdgeAnalysisFromLocalFile($asset, $localPath);
        $detectedExt = ImageProbe::extFromMime($asset->mime);
        if ($detectedExt) {
            $asset->ext = $detectedExt;
        }

        $this->uploadToArchiveFromPath($asset, $localPath);
        $this->em->flush();
    }

    #[AsTransitionListener(WF::WORKFLOW_NAME, AssetFlow::TRANSITION_AI_TASK)]
    public function onAiTask(TransitionEvent $event): void
    {
        $asset = $this->getAsset($event);
        $this->runNextAiTask($asset);
        $this->em->flush();
    }

    #[AsTransitionListener(WF::WORKFLOW_NAME, AssetFlow::TRANSITION_LOCAL_OCR)]
    public function onLocalOcr(TransitionEvent $event): void
    {
        $asset = $this->getAsset($event);
        $sourceUrl = $this->preferredLocalOcrUrl($asset);
        if ($sourceUrl === null) {
            $this->logger->warning('Local OCR skipped for {id}: no source URL', ['id' => $asset->id]);
            return;
        }

        $analysis = $this->aiToolsOcrService->analyzeUrl($sourceUrl);
        if (!is_array($analysis) || $analysis === []) {
            $this->logger->warning('Local OCR returned no result for {id}', ['id' => $asset->id]);
            return;
        }

        $asset->localOcrStatus = isset($analysis['status']) && is_numeric($analysis['status'])
            ? (int) $analysis['status']
            : null;
        $asset->localOcrError = is_string($analysis['error'] ?? null) ? $analysis['error'] : null;

        if (($analysis['ok'] ?? true) !== true) {
            $asset->context ??= [];
            $asset->context['local_ocr'] = $analysis;
            $this->em->flush();
            return;
        }

        $ocr = $analysis['ocr'] ?? [];
        if (!is_array($ocr)) {
            $ocr = [];
        }

        $asset->context ??= [];
        $asset->context['local_ocr'] = $analysis;
        if (isset($ocr['text']) && is_string($ocr['text']) && trim($ocr['text']) !== '') {
            $asset->context['ocr'] = mb_substr($ocr['text'], 0, 20000);
            $asset->context['ocr_chars'] = mb_strlen($asset->context['ocr']);
        }

        $asset->localOcrText = is_string($ocr['text'] ?? null) ? trim((string) $ocr['text']) : null;
        $asset->localOcrConfidence = isset($ocr['mean_confidence']) && is_numeric($ocr['mean_confidence'])
            ? (float) $ocr['mean_confidence']
            : null;
        $asset->localOcrPrimaryType = is_string($analysis['primary_type'] ?? null) ? $analysis['primary_type'] : null;
        $asset->localOcrSourceUrl = $sourceUrl;
        $asset->localOcrProvider = 'ai-tools';
        $asset->localOcrModel = 'tesseract';
        $asset->localOcrAt = new \DateTimeImmutable();
        $asset->localOcrStatus = 200;
        $asset->localOcrError = null;

        if ($asset->aiDocumentType === null && is_string($asset->localOcrPrimaryType) && $asset->localOcrPrimaryType !== '') {
            $asset->aiDocumentType = $asset->localOcrPrimaryType;
        }

        $tasks = $this->localOcrNextTasks($analysis);
        if ($tasks !== []) {
            $asset->aiQueue = array_values(array_unique(array_merge($asset->aiQueue, $tasks)));
        }

        $this->em->flush();
    }

    #[AsTransitionListener(WF::WORKFLOW_NAME, AssetFlow::TRANSITION_TRIAGE)]
    public function onTriage(TransitionEvent $event): void
    {
        $asset = $this->getAsset($event);
        if (!$asset->mime || !str_starts_with($asset->mime, 'image/')) {
            $this->logger->info('Skipping triage for non-image asset {id} ({mime})', [
                'id' => $asset->id,
                'mime' => $asset->mime,
            ]);
            return;
        }

        $imageUrl = $this->preferredTriageImageUrl($asset);
        if ($imageUrl === null) {
            throw new RuntimeException(sprintf('No triage image URL available for asset %s.', $asset->id));
        }

        $startedAt = microtime(true);
        $payload = $this->aiToolsObserveService->observeImage($imageUrl, 'auto');
        $claims = is_array($payload['claims'] ?? null) ? $payload['claims'] : [];
        $run = is_array($payload['run'] ?? null) ? $payload['run'] : [];
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        // Persist argus's claims (observe:caption/description/tag/classification/text) into
        // the real claims store, not just the context blob below — this is what
        // ClaimSearchSync/ClaimMetaResolver mirror onto claimCaption/claimProse/claimSubjects/
        // claimType for search. Previously these claims were computed but never ingested.
        $rawClaims = $this->toRawClaims($claims);
        if ($rawClaims !== []) {
            $this->claimIngestor->record(
                $asset->dataset ?? 'mediary',
                Asset::class,
                $asset->id,
                'ai-tools',
                $rawClaims,
                new RunMeta(
                    model: is_string($run['model'] ?? null) ? $run['model'] : 'auto',
                    durationMs: $durationMs,
                ),
            );
            // ClaimIngestor holds its own (survos_claims) EM — flush via it, not $this->em,
            // or the claims silently never commit.
            $this->claimIngestor->flush();
            $this->claimSearchSync->sync([$asset]);
        }

        $asset->context ??= [];
        $asset->context['triage'] = [
            'ok' => true,
            'source_url' => $imageUrl,
            'schema_version' => $payload['schema_version'] ?? null,
            'claims' => $claims,
            'run' => $run,
            'duration_ms' => $durationMs,
            'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $classification = $this->firstClaimValue($claims, 'observe:classification');
        if ($classification !== null) {
            $asset->localOcrPrimaryType = $classification;
            $asset->aiDocumentType = $classification;
        }

        $text = $this->longestClaimValue($claims, 'observe:text');
        if ($text !== null && trim($text) !== '') {
            $asset->localOcrText = mb_substr(trim($text), 0, 20000);
            $asset->context['ocr'] = $asset->localOcrText;
            $asset->context['ocr_chars'] = mb_strlen($asset->localOcrText);
        }

        $asset->localOcrSourceUrl = $imageUrl;
        $asset->localOcrProvider = 'ai-tools';
        $asset->localOcrModel = is_string($run['model'] ?? null) ? $run['model'] : 'auto';
        $asset->localOcrAt = new \DateTimeImmutable();
        $asset->localOcrStatus = 200;
        $asset->localOcrError = null;

        $this->recordCompletedTask($asset, 'triage', [
            'schema_version' => $payload['schema_version'] ?? null,
            'claims' => $claims,
            'run' => $run,
            'source_url' => $imageUrl,
        ]);

        $this->logger->info('Asset triage recorded {count} claims for {id}', [
            'count' => count($claims),
            'id' => $asset->id,
        ]);
    }

    #[AsTransitionListener(WF::WORKFLOW_NAME, AssetFlow::TRANSITION_DELETE)]
    public function onDelete(TransitionEvent $event): void
    {
        $asset = $this->getAsset($event);
        $id = $asset->id;

        // Remove the archived master from storage if we put one there.
        if ($this->archiveStorage !== null
            && $asset->storageKey
            && $this->archiveStorage->fileExists($asset->storageKey)
        ) {
            $this->archiveStorage->delete($asset->storageKey);
            $this->logger->info('delete[{id}]: removed archive object {key}', [
                'id' => $id,
                'key' => $asset->storageKey,
            ]);
        }

        // Delete the row. The 'deleted' marking this transition sets is moot — the
        // entity is removed here, and the post-apply flush is a no-op for it.
        $this->em->remove($asset);
        $this->em->flush();

        $this->logger->info('delete[{id}]: asset row removed', ['id' => $id]);
    }

    #[AsTransitionListener(WF::WORKFLOW_NAME, AssetFlow::TRANSITION_FETCH_IIIF)]
    public function onFetchIiif(TransitionEvent $event): void
    {
        $asset = $this->getAsset($event);

        $hints = is_array($asset->sourceMeta) ? $asset->sourceMeta : [];
        $manifestRef = $hints['iiif_manifest'] ?? $hints['iiifManifest'] ?? null;

        if ($manifestRef === null || $manifestRef === '') {
            return;
        }

        try {
            $cachedManifest = $asset->iiifManifestEntity?->manifestJson;
            if (!isset($hints['iiif_manifest_json']) && is_array($cachedManifest) && $cachedManifest !== []) {
                $hints['iiif_manifest_json'] = $cachedManifest;
            }

            if (is_string($manifestRef) && !isset($hints['iiif_manifest_json'])) {
                $tmpFile = tempnam(sys_get_temp_dir(), 'iiif_manifest_');
                if ($tmpFile === false) {
                    throw new RuntimeException('Unable to allocate temporary file for IIIF fetch.');
                }

                try {
                    $this->downloadUrl($manifestRef, $tmpFile);
                    $json = file_get_contents($tmpFile);
                    if ($json !== false) {
                        $decoded = json_decode($json, true);
                        if (is_array($decoded)) {
                            $hints['iiif_manifest_json'] = $decoded;
                        }
                    }
                } finally {
                    if (is_file($tmpFile)) {
                        @unlink($tmpFile);
                    }
                }
            }

            $hints = $this->iiifManifestService->attachFromContextHints($asset, $hints);
            $asset->sourceMeta = array_replace($asset->sourceMeta ?? [], $hints);

            $metadataMap = $this->iiifMetadataMap($asset->iiifManifestEntity?->manifestJson ?? null);

            // Fill canonical source metadata conservatively: only when empty.
            if (!isset($asset->sourceMeta['dcterms:title']) || trim((string) $asset->sourceMeta['dcterms:title']) === '') {
                $title = trim((string) ($asset->iiifManifestEntity?->label ?? ''));
                if ($title === '') {
                    $title = $this->firstIiifValue($metadataMap, ['title']);
                }
                if ($title !== '') {
                    $asset->sourceMeta['dcterms:title'] = $title;
                }
            }

            if (!isset($asset->sourceMeta['dcterms:description']) || trim((string) $asset->sourceMeta['dcterms:description']) === '') {
                $description = $this->firstIiifValue($metadataMap, ['description', 'summary', 'abstract']);
                if ($description !== '') {
                    $asset->sourceMeta['dcterms:description'] = $description;
                }
            }

            // Keep parsed IIIF facets in sourceMeta for indexing/debug.
            $asset->sourceMeta ??= [];
            if (!isset($asset->sourceMeta['iiif_subjects'])) {
                $subjects = $this->iiifValues($metadataMap, ['subject', 'subjects', 'topic', 'topics']);
                if ($subjects !== []) {
                    $asset->sourceMeta['iiif_subjects'] = $subjects;
                }
            }
            if (!isset($asset->sourceMeta['iiif_keywords'])) {
                $keywords = $this->iiifValues($metadataMap, ['keyword', 'keywords']);
                if ($keywords !== []) {
                    $asset->sourceMeta['iiif_keywords'] = $keywords;
                }
            }

            // Once IIIF metadata is available, prefer a deterministic IIIF-derived thumbnail
            // over any earlier fallback/insecure value.
            $iiifThumb = $this->extractUrlFromMixed($asset->sourceMeta['iiif_thumbnail_url'] ?? null)
                ?? $this->extractUrlFromMixed($asset->sourceMeta['thumbnail_url'] ?? null)
                ?? $this->iiifThumbnailUrl($asset);

            if (is_string($iiifThumb) && $iiifThumb !== '') {
                $asset->smallUrl = $iiifThumb;
            }

            $this->em->flush();
        } catch (UnrecoverableMessageException $e) {
            $asset->sourceMeta ??= [];
            $asset->sourceMeta['iiif_error'] = $e->getMessage();
            $this->logger->warning('Skipping IIIF manifest fetch for {id}: {message}', [
                'id' => $asset->id,
                'message' => $e->getMessage(),
            ]);
            $this->em->flush();
        }
    }

        /**
     * @throws FilesystemException
     * @throws TransportExceptionInterface
     */
    #[AsTransitionListener(WF::WORKFLOW_NAME, AssetFlow::TRANSITION_ARCHIVE)]
    public function onArchive(TransitionEvent $event): void
    {
        $asset = $this->getAsset($event);
        if ($this->archiveStorage === null) {
            throw new RuntimeException('archiveStorage (museado) is not configured.');
        }

        // Already in our bucket? (e.g. old-pipeline assets under orig/, or a prior
        // run). Reuse the existing object — no re-download, no re-upload. /info
        // then runs against s3://museado/{storageKey} as usual.
        if (is_string($asset->storageKey) && $asset->storageKey !== ''
            && $this->archiveStorage->fileExists($asset->storageKey)
        ) {
            $asset->storageBackend ??= 'archive';
            $asset->archiveUrl ??= $this->assetRegistry->s3Url($asset);
            $asset->statusCode = 200;
            $this->logger->info('archive[{id}]: already in bucket → reuse {key} (no download, no upload)', [
                'id' => $asset->id,
                'key' => $asset->storageKey,
            ]);
            $this->em->flush();
            $this->warmThumbnailCache($asset);
            return;
        }

        $url = $asset->originalUrl;

        // Stream the source master straight into museado — constant memory, no
        // local copy, no re-encode (bytes verbatim, so any embedded metadata is
        // preserved). imgproxy then reads it via s3://, so no downstream call
        // ever hits the origin server again.
        $response = $this->httpClient->request('GET', $url, [
            'timeout' => $this->serviceHttpTimeoutSeconds,
        ]);
        $status = $response->getStatusCode();
        $asset->statusCode = $status;
        if ($status !== 200) {
            throw new RuntimeException(sprintf('Source returned HTTP %d for asset %s (%s)', $status, $asset->id, $url));
        }

        $headers = $response->getHeaders();
        $contentType = $headers['content-type'][0] ?? null;
        if (is_string($contentType) && $contentType !== '') {
            $asset->mime = trim(explode(';', $contentType)[0]);
        }

        // Extension for the storage key: prefer the detected mime, else the URL.
        $ext = null;
        if (is_string($asset->mime) && str_contains((string) $asset->mime, '/')) {
            try {
                $ext = MediaKeyService::extensionFromMime($asset->mime);
            } catch (\Throwable) {
                $ext = null;
            }
        }
        $ext = $ext ?: (strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'bin');
        $asset->ext = $ext;

        // Buffer the source: the S3 adapter must seek (size/multipart) and we need
        // to sha256 the bytes for the content-addressed key. php://temp stays in
        // memory up to 8MB, then spills to disk (bounded memory).
        $httpStream = StreamWrapper::createResource($response, $this->httpClient);
        $buffer = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'r+b');
        $key = null;
        $bytes = null;
        try {
            // stream_copy_to_stream returns the ACTUAL bytes copied (int), or false
            // on error — more reliable than the Content-Length header, which sources
            // often omit (chunked).
            $bytes = stream_copy_to_stream($httpStream, $buffer);
            fclose($httpStream);
            $httpStream = null;

            if (!$bytes) { // null (unreached), false (stream error), or 0 (empty)
                throw new RuntimeException(sprintf(
                    'Downloaded zero bytes for asset %s from %s — refusing to archive an empty master.',
                    $asset->id,
                    $url
                ));
            }

            // Content-addressed under orig/, matching the existing ~800k masters,
            // so identical bytes dedup and we never re-upload. (Asset id stays the
            // canonical xxh3-of-URL via MediaIdentity; only the storage path is
            // content-derived.)
            rewind($buffer);
            $ctx = hash_init('sha256');
            hash_update_stream($ctx, $buffer);
            $sha = hash_final($ctx);
            $asset->context ??= [];
            $asset->context['sha256'] = $sha;
            $key = sprintf('orig/%s/%s/%s.%s', substr($sha, 0, 2), substr($sha, 2, 2), $sha, $ext);

            if (!$this->archiveStorage->fileExists($key)) {
                rewind($buffer);
                $this->archiveStorage->writeStream($key, $buffer, ['visibility' => Visibility::PUBLIC]);
                $this->logger->info('archive[{id}]: UPLOADED {key} ({bytes} bytes) from {url}', [
                    'id' => $asset->id, 'key' => $key, 'bytes' => $bytes, 'url' => $url,
                ]);
            } else {
                $this->logger->info('archive[{id}]: dedup hit → {key} already present ({bytes} bytes), skipping upload', [
                    'id' => $asset->id, 'key' => $key, 'bytes' => $bytes,
                ]);
            }
        } finally {
            if (is_resource($httpStream)) {
                fclose($httpStream);
            }
            if (is_resource($buffer)) {
                fclose($buffer);
            }
        }

        $asset->storageKey = $key;
        $asset->storageBackend = 'archive';
        // Master byte size — the actual bytes streamed (a real metric vs. derivatives).
        $asset->size = $bytes;
        $asset->archiveUrl = $this->assetRegistry->s3Url($asset); // public HTTP URL for browsers

        $this->em->flush();
        $this->warmThumbnailCache($asset);
    }

    /**
     * Warm imgproxy's S3 result cache for the search thumbnail — fire-and-forget, not
     * gated behind any option. Every image needs a thumbnail; the only question is
     * whether imgproxy reads it from our S3 (storageKey, just set above) or the
     * remote origin, and by this point in onArchive() that's always resolved. See
     * WarmImgproxyCacheMessageHandler for the actual GET.
     */
    private function warmThumbnailCache(Asset $asset): void
    {
        $url = $this->assetRegistry->imgProxyUrl($asset, MediaUrlGenerator::PRESET_SMALL);
        if ($url !== null) {
            $this->messageBus->dispatch(new WarmImgproxyCacheMessage($url));
        }
    }

    #[AsTransitionListener(WF::WORKFLOW_NAME, AssetFlow::TRANSITION_INFO)]
    public function onInfo(TransitionEvent $event): void
    {
        $asset = $this->getAsset($event);
        // /info runs against the s3:// master once it's archived; falls back to
        // the original URL only if the archive step hasn't run.
        $source = $asset->storageKey
            ? $this->assetRegistry->s3SourceUrl($asset)
            : $asset->originalUrl;

        $asset->context ??= [];
        $asset->context['info_source'] = $source;
        $this->logger->info('info[{id}]: /info source = {source}', [
            'id' => $asset->id,
            'source' => $source,
        ]);

        $cachedInfo = $asset->context['info'] ?? null;
        if (is_array($cachedInfo)) {
            $info = $cachedInfo;
            $this->logger->info('info[{id}]: reprocessing cached /info payload', [
                'id' => $asset->id,
            ]);
        } else {
            // imgproxy PRO /info against the s3:// master — one remote call, NO
            // download to our server, NO nesting (the source is a real object in our
            // bucket, never another imgproxy URL).
            //
            // The default response carries format, dimensions, mime_type, size, exif,
            // iptc, xmp, orientation; we add perceptual hashes and face detection. Notes:
            //  - blurhash needs TWO components (bh:x:y); a valueless token 404s.
            //  - detect_objects returns face boxes (this deployment's model is a face
            //    detector) — kept, it's cheap, already stored, and drives faceCount below.
            //  - classify (generic ImageNet-style labels) was dropped: weak on archival
            //    photography. ai-tools/argus's Florence-2 triage (see onTriage below)
            //    produces far better observe:tag / observe:description claims and is now
            //    the source of truth for tags/descriptions.
            $info = $this->imgproxyUrlBuilder->info($source, [
                'size',
                'format',
                'dimensions',
                'exif:1:1',
                'thumb_hash',
                'blurhash:4:3',
                'perceptual_hash',
                'average',
                'dominant_colors',
                'detect_objects:1',
            ]);

            $asset->context ??= [];
            $asset->context['info'] = $info;
            $asset->context['info_source'] = $source;
            $asset->context['info_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        }
        $asset->statusCode = 200;
        $this->applyInfoMetadata($asset, $info);

        $this->em->flush();
    }

    /** @param array<string, mixed> $info */
    private function applyInfoMetadata(Asset $asset, array $info): void
    {
        $dimensions = is_array($info['dimensions'] ?? null) ? $info['dimensions'] : [];
        $width = $this->infoPositiveInt($info, 'width')
            ?? $this->infoPositiveInt($dimensions, 'width')
            ?? $this->infoPositiveInt($dimensions, 0);
        $height = $this->infoPositiveInt($info, 'height')
            ?? $this->infoPositiveInt($dimensions, 'height')
            ?? $this->infoPositiveInt($dimensions, 1);

        if ($width !== null && $height !== null) {
            $asset->width = $width;
            $asset->height = $height;
        }

        $size = $this->infoPositiveInt($info, 'size');
        if ($size !== null) {
            $asset->size = $size;
        }

        $mime = $this->infoString($info, 'mime_type') ?? $this->infoString($info, 'mime');
        $format = $this->infoString($info, 'format');
        if ($mime === null && $format !== null) {
            $mime = $this->mimeFromFormat($format);
        }
        if ($mime !== null) {
            $asset->mime = $mime;
        }

        if ($mime !== null) {
            try {
                $asset->ext = MediaKeyService::extensionFromMime($mime);
            } catch (\Throwable) {
            }
        }

        if ($format !== null) {
            $asset->ext = match (strtolower($format)) {
                'jpeg' => 'jpg',
                'tiff' => 'tif',
                default => strtolower($format),
            };
        }

        // classification (imgproxy's classify:5 labels) is no longer requested — see the
        // comment in onInfo(). $asset->classification is left in place but unpopulated
        // going forward; observe:tag claims from argus triage are the tag source now.
        [$objectIdentifiers, $objectIdentifierConfidences] = $this->objectIdentifierData($info);
        $asset->objectIdentifiers = $objectIdentifiers;
        $asset->objectIdentifierConfidences = $objectIdentifierConfidences;
        $asset->faceCount = $this->faceCount($info);
    }

    /**
     * Count of raw face-box detections from imgproxy's detect_objects (this deployment's
     * object-detection model is a face detector). Deliberately NOT deduped like
     * objectIdentifierData() — every box counts, since 3 faces all labelled "face" must
     * count as 3, not collapse to 1 unique label. Powers the faceCount facet: 1 = portrait,
     * 2 = couple, 3-5 = small group, 6+ = large group.
     *
     * @param array<string, mixed> $info
     */
    private function faceCount(array $info): ?int
    {
        $entries = $info['objects'] ?? null;
        return is_array($entries) ? count($entries) : null;
    }

    /**
     * @param array<string, mixed> $info
     * @return array{0: string[]|null, 1: array<string, float>|null}
     */
    private function objectIdentifierData(array $info): array
    {
        $entries = $info['objects'] ?? null;
        if (!is_array($entries)) {
            return [null, null];
        }

        $labels = [];
        $confidenceStore = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['class_name'] ?? null)) {
                continue;
            }

            $name = trim($entry['class_name']);
            if ($name === '') {
                continue;
            }

            $labels[] = $name;
            $confidence = $entry['confidence'] ?? null;
            if (is_numeric($confidence)) {
                $confidence = (float) $confidence;
                $confidenceStore[$name] = max($confidenceStore[$name] ?? 0.0, $confidence);
            }
        }

        return [$this->uniqueNonEmptyStrings($labels), $confidenceStore !== [] ? $confidenceStore : null];
    }

    /**
     * @param array<?string> $values
     * @return string[]|null
     */
    private function uniqueNonEmptyStrings(array $values): ?array
    {
        $strings = array_values(array_unique(array_filter(
            array_map(static fn (?string $value): ?string => $value !== null ? trim($value) : null, $values),
            static fn (?string $value): bool => $value !== null && $value !== '',
        )));

        return $strings !== [] ? $strings : null;
    }

    /** @param array<mixed> $values */
    private function infoPositiveInt(array $values, int|string $key): ?int
    {
        if (!array_key_exists($key, $values) || !is_numeric($values[$key])) {
            return null;
        }

        $value = (int) $values[$key];
        return $value > 0 ? $value : null;
    }

    /** @param array<string, mixed> $values */
    private function infoString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    private function mimeFromFormat(string $format): ?string
    {
        return match (strtolower(trim($format))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'tif', 'tiff' => 'image/tiff',
            'svg' => 'image/svg+xml',
            default => null,
        };
    }

    /**
     *
     * Because onCompleted happens BEFORE the specific transition onCompleted,
     * we need to match this event exactly.  Otherwise, the next events are dispatched too soon.
     */
    // #[AsCompletedListener(WF::TRANSITION_ARCHIVE, priority: 1000)] NOT THIS!  See note above
    #[AsCompletedListener(WF::WORKFLOW_NAME, priority: 1000)]
    public function onCompleted(CompletedEvent $event): void
    {
        $asset = $this->getAsset($event);
        $this->em->flush();

        $transitionName = $event->getTransition()->getName();
        if (in_array($transitionName, [WF::TRANSITION_QUEUE_AI, WF::TRANSITION_AI_TASK, WF::TRANSITION_LOCAL_OCR], true) && !$asset->aiLocked) {
            if (!empty($asset->aiQueue) && $this->assetWorkflow->can($asset, WF::TRANSITION_AI_TASK)) {
                $this->dispatchTransition($asset, WF::TRANSITION_AI_TASK);
            } elseif (empty($asset->aiQueue) && $this->assetWorkflow->can($asset, WF::TRANSITION_AI_DONE)) {
                $this->dispatchTransition($asset, WF::TRANSITION_AI_DONE);
            }
        }

        // Publish to the client's callback once there is archived state to report.
        //
        // The gate was `marking === PLACE_ANALYZED`, which assumes assets COME TO
        // REST there. They don't — analyzed is a waypoint on the way to complete,
        // and a worker that runs the chain through in one pass leaves the asset in
        // complete without any completed-event ever seeing it in analyzed. So the
        // notification silently never fired for exactly the assets that succeeded
        // fastest. Measured 2026-08-17 after loading 7 datasets: 66 assets archived
        // and 63 /info-probed in mediary, and the client (musdig) still saw 11 —
        // the same "mediary holds state the app can't see" gap as mediary#7 itself,
        // reintroduced one place further along.
        //
        // archiveUrl is the real precondition: it means there IS something worth
        // telling the client. Same rule ReplayWebhooksCommand selects on, so a live
        // delivery and a replay now agree about which assets qualify.
        $callbackUrl = $asset->context['callback_url'] ?? null;
        if ($callbackUrl && $asset->archiveUrl) {
            $this->fireWebhook($asset, (string) $callbackUrl);
        }

        // don't detach until AFTER completed is called, or we won't save the marking.
        $this->em->detach($asset);
    }

    #[AsTransitionListener(WF::WORKFLOW_NAME, WF::TRANSITION_ANALYZE)]
    public function onLocalAnalyze(TransitionEvent $event): void
    {
        // Analysis now happens AFTER archive using S3-backed URLs
        $asset = $this->getAsset($event);

        if (!$asset->mime || !str_starts_with($asset->mime, 'image/')) {
            $this->logger->info("Skipping analysis for non-image asset ({$asset->mime})");
            return;
        }

        // Thumbhash and palette were computed in onDownload while the file was local.
        // Only fall back to the archive URL fetch if they're missing (e.g. older assets).
        $asset->context ??= [];
        $tasks = $asset->context['tasks'] ?? ['thumbhash', 'palette'];

        if (in_array('ocr', $tasks, true) && empty($asset->context['ocr'])) {
            $ocrSourceUrl = $asset->smallUrl ?? $asset->archiveUrl ?? null;
            if ($ocrSourceUrl) {
                $ocrTmp = tempnam(sys_get_temp_dir(), 'asset_ocr_');
                if ($ocrTmp !== false) {
                    try {
                        $this->downloadUrl($ocrSourceUrl, $ocrTmp);
                        $ocrText = $this->ocrService->extractText($ocrTmp, $asset->mime);
                        if ($ocrText !== null && $ocrText !== '') {
                            $asset->context['ocr'] = $ocrText;
                            $asset->context['ocr_chars'] = mb_strlen($ocrText);
                        }
                    } finally {
                        if (is_file($ocrTmp)) {
                            unlink($ocrTmp);
                        }
                    }
                }
            }
        }

        if (empty($asset->context['thumbhash']) && $asset->archiveUrl) {
            $localForThumbhash = $this->localImagePath($asset, preferSmall: true);
            if (is_string($localForThumbhash) && $localForThumbhash !== '') {
                $this->logger->info('onLocalAnalyze: thumbhash missing, using local small derivative');
                [$tw, $th, $pixels] = $this->resizeForThumbHash($localForThumbhash, 100);
                $hash = Thumbhash::RGBAToHash($tw, $th, $pixels, 192, 192);
                $asset->context['thumbhash'] = Thumbhash::convertHashToString($hash);
                unset($pixels);
            } else {
                $this->logger->info('onLocalAnalyze: thumbhash missing, fetching from archive URL (fallback)');
                [$tw, $th, $pixels] = $this->assetPreviewService->resizeForThumbHashFromUrl($asset->archiveUrl);
                $hash = Thumbhash::RGBAToHash($tw, $th, $pixels, 192, 192);
                $asset->context['thumbhash'] = Thumbhash::convertHashToString($hash);
                unset($pixels);
            }
        }

        if (empty($asset->context['colors']) && $asset->archiveUrl) {
            $localForPalette = $this->localImagePath($asset, preferSmall: true);
            $this->assetPreviewService->maybeComputePaletteAndPhash(
                $asset,
                self::THUMBHASH_PRESET,
                $localForPalette ?? $asset->archiveUrl
            );
        }

        $this->em->flush();
//        $this->em->detach($asset);
    }

    /**
     * Runs exactly one pending AI task from aiQueue.
     *
     * @return string|null Task name that ran, or null when skipped.
     */
    public function runNextAiTask(Asset $asset, bool $completeWhenQueueEmpty = false, bool $force = false): ?string
    {
        if ($asset->aiLocked) {
            $this->logger->info('Asset AI pipeline locked for {id}; skipping.', ['id' => $asset->id]);
            return null;
        }

        if (empty($asset->aiQueue)) {
            if ($completeWhenQueueEmpty) {
                $this->finishAiPipeline($asset);
            }
            return null;
        }

        $taskName = (string) array_shift($asset->aiQueue);
        $taskOverride = $this->consumeTaskOverride($asset, $taskName);
        $context = $asset->context ?? [];
        if (isset($taskOverride['model']) && is_string($taskOverride['model']) && $taskOverride['model'] !== '') {
            $context['model_hint'] = $taskOverride['model'];
        }
        $force = $force || !empty($taskOverride['force']);

        // Run via AssetAiExecutor — the ai-workflow bridge that REPLACED the removed ai-pipeline
        // handler layer (registry → AssetSubject (grounded context) → sidecar cache + claims).
        // AssetController already uses it; AssetWorkflow had been left on the now-empty
        // aiTaskHandlers map (every task resolved as "unknown"). See AssetAiExecutor docblock.
        try {
            $result = $this->executor->run($asset, $taskName, $context, $force);
            if (!($result['ok'] ?? false)) {
                $this->logger->warning(
                    'AI task "{task}" not run on asset {id}: {reason}',
                    ['task' => $taskName, 'id' => $asset->id, 'reason' => $result['reason'] ?? 'unknown'],
                );
                $this->recordCompletedTask($asset, $taskName, ['skipped' => true, 'reason' => $result['reason'] ?? 'unknown']);
            } else {
                $this->recordCompletedTask($asset, $taskName, [
                    'cached'   => $result['cached'] ?? false,
                    'response' => $result['response'] ?? [],
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'AI task "{task}" failed on asset {id}: {error}',
                ['task' => $taskName, 'id' => $asset->id, 'error' => $e->getMessage()],
            );
            $this->recordCompletedTask($asset, $taskName, ['failed' => true, 'error' => $e->getMessage()]);
        }

        if ($completeWhenQueueEmpty && empty($asset->aiQueue)) {
            $this->finishAiPipeline($asset);
        }

        return $taskName;
    }

    private function dispatchTransition(Asset $asset, string $transition): void
    {
        $msg = new TransitionMessage($asset->id, Asset::class, $transition, WF::WORKFLOW_NAME);
        $this->messageBus->dispatch($msg, $this->asyncQueueLocator->stamps($msg));
    }

    /** @return array<string, mixed> */
    /**
     * @return array{inputs: array<string,mixed>, imageSource: string}
     */
    private function buildAiInputs(Asset $asset, string $taskName, array $taskOverride = []): array
    {
        ['url' => $imageUrl, 'source' => $imageSource] = $this->selectTaskImageUrl($asset, $taskName, $taskOverride);

        $inputs = array_filter([
            'image_url' => $imageUrl,
            'image_path' => $this->localImagePath($asset, preferSmall: $taskName === AssetAiTask::ENRICH_FROM_THUMBNAIL->value),
            'mime' => $asset->mime ?? null,
        ], static fn ($value) => $value !== null);

        return [
            'inputs' => $inputs,
            'imageSource' => $imageSource,
        ];
    }

    /** @return array{url: ?string, source: string} */
    private function selectTaskImageUrl(Asset $asset, string $taskName, array $taskOverride = []): array
    {
        $imagePreference = $taskOverride['image_source_preference'] ?? null;
        if (is_string($imagePreference)) {
            if ($imagePreference === 'full') {
                return $this->preferredFullImageUrl($asset);
            }

            if (in_array($imagePreference, ['thumb', 'thumbnail', 'small'], true)) {
                $thumb = $this->preferredThumbnailUrl($asset);
                return $thumb['url'] !== null ? $thumb : $this->preferredFullImageUrl($asset);
            }
        }

        if ($taskName === AssetAiTask::TRANSCRIBE_HANDWRITING->value) {
            return $this->preferredFullImageUrl($asset);
        }

        if ($taskName === AssetAiTask::ENRICH_FROM_THUMBNAIL->value) {
            $thumb = $this->preferredThumbnailUrl($asset);
            if ($thumb['url'] !== null) {
                return $thumb;
            }

            return $this->preferredFullImageUrl($asset);
        }

        return $this->preferredFullImageUrl($asset);
    }

    /** @return array{url: ?string, source: string} */
    private function preferredFullImageUrl(Asset $asset): array
    {
        $archiveUrl = $this->effectiveArchiveUrl($asset);
        if ($archiveUrl !== null) {
            return ['url' => $archiveUrl, 'source' => 'archive_url'];
        }

        $iiifFullUrl = $this->iiifFullUrl($asset);
        if ($iiifFullUrl !== null) {
            return ['url' => $iiifFullUrl, 'source' => 'iiif_full'];
        }

        if ($asset->originalUrl !== '') {
            return ['url' => $asset->originalUrl, 'source' => 'original_url'];
        }

        if ($asset->smallUrl !== null && $asset->smallUrl !== '') {
            return ['url' => $asset->smallUrl, 'source' => 'small_url'];
        }

        return ['url' => null, 'source' => 'none'];
    }

    /** @return array{url: ?string, source: string} */
    private function preferredThumbnailUrl(Asset $asset): array
    {
        $sourceMeta = $asset->sourceMeta ?? [];

        $candidates = [
            'source_meta.thumbnail_url' => $sourceMeta['thumbnail_url'] ?? null,
            'source_meta.thumb_url' => $sourceMeta['thumb_url'] ?? null,
            'source_meta.iiif_thumbnail_url' => $sourceMeta['iiif_thumbnail_url'] ?? null,
            'source_meta.small_url' => $sourceMeta['small_url'] ?? null,
            'source_meta.preview_url' => $sourceMeta['preview_url'] ?? null,
            'source_meta.thumbnail' => $sourceMeta['thumbnail'] ?? null,
            'source_meta.thumb' => $sourceMeta['thumb'] ?? null,
        ];

        foreach ($candidates as $source => $candidate) {
            $url = $this->extractUrlFromMixed($candidate);
            if ($url !== null) {
                return ['url' => $url, 'source' => $source];
            }
        }

        $iiifThumb = $this->iiifThumbnailUrl($asset);
        if ($iiifThumb !== null) {
            return ['url' => $iiifThumb, 'source' => 'iiif_thumbnail'];
        }

        if ($asset->smallUrl !== null && $asset->smallUrl !== '') {
            return ['url' => $asset->smallUrl, 'source' => 'small_url'];
        }

        return [
            'url' => $this->assetRegistry->imgProxyUrl($asset, MediaUrlGenerator::PRESET_SMALL),
            'source' => 'imgproxy_small',
        ];
    }

    private function iiifThumbnailUrl(Asset $asset): ?string
    {
        $iiifBase = $asset->iiifManifestEntity?->imageBase ?? $asset->sourceMeta['iiif_base'] ?? null;
        if (!is_string($iiifBase) || $iiifBase === '') {
            return null;
        }

        return rtrim($iiifBase, '/') . '/full/!512,512/0/default.jpg';
    }

    private function extractUrlFromMixed(mixed $value): ?string
    {
        if (is_string($value)) {
            return $this->isLikelyUrl($value) ? $value : null;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach (['url', 'id', '@id', 'href', 'src'] as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }

            $url = $this->extractUrlFromMixed($value[$key]);
            if ($url !== null) {
                return $url;
            }
        }

        foreach ($value as $item) {
            $url = $this->extractUrlFromMixed($item);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function isLikelyUrl(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return (bool) filter_var($value, FILTER_VALIDATE_URL);
    }

    /** @return array<string,mixed> */
    private function consumeTaskOverride(Asset $asset, string $taskName): array
    {
        $context = $asset->context ?? [];
        $overrides = $context['ai_task_overrides'] ?? null;
        if (!is_array($overrides) || !isset($overrides[$taskName]) || !is_array($overrides[$taskName])) {
            return [];
        }

        $override = $overrides[$taskName];
        unset($overrides[$taskName]);
        $context['ai_task_overrides'] = $overrides;
        $asset->context = $context;

        return $override;
    }

    private function recordCompletedTask(Asset $asset, string $taskName, array $result): void
    {
        $asset->aiCompleted[] = [
            'task' => $taskName,
            'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'result' => $result,
        ];

        if ($taskName === AssetAiTask::CLASSIFY->value
            && empty($result['failed'])
            && empty($result['skipped'])
        ) {
            $asset->aiDocumentType = $result['type'] ?? null;
        }

        $enrichment = MediaEnrichment::fromCompleted($asset->aiCompleted);
        $asset->mediaEnrichment = $enrichment->toArray();
        $asset->enriched = $enrichment;
    }

    private function applyEdgeAnalysisFromLocalFile(Asset $asset, string $localPath): void
    {
        if (!str_starts_with((string) $asset->mime, 'image/')) {
            return;
        }

        $analysis = $this->edgeAnalysisService->analyzeLocalFile($localPath, $asset->mime);
        if (!is_array($analysis) || $analysis === []) {
            return;
        }

        $asset->context ??= [];
        $asset->context['edge_analysis'] = $analysis;
        $asset->context['has_text_likely'] = (bool) ($analysis['has_text_likely'] ?? false);
        $asset->context['typed_likely'] = (bool) ($analysis['typed_likely'] ?? false);
        $asset->context['handwritten_likely'] = (bool) ($analysis['handwritten_likely'] ?? false);

        $primaryType = $analysis['primary_type'] ?? null;
        if (is_string($primaryType) && $primaryType !== '') {
            $asset->context['type_estimate'] = $primaryType;
            $asset->aiDocumentType ??= $primaryType;
        }

        if (!isset($asset->context['tasks']) || !is_array($asset->context['tasks'])) {
            $tasks = ['thumbhash', 'palette'];
            if ($this->shouldAutoQueueOcr($analysis)) {
                $tasks[] = 'ocr';
                $asset->context['ocr_auto_queued'] = true;
            }
            $asset->context['tasks'] = $tasks;
        }
    }

    /** @param array<string, mixed> $analysis */
    private function shouldAutoQueueOcr(array $analysis): bool
    {
        if (($analysis['has_text_likely'] ?? false) === true) {
            return true;
        }

        $primaryType = is_string($analysis['primary_type'] ?? null)
            ? (string) $analysis['primary_type']
            : null;

        return in_array($primaryType, ['document', 'text_page', 'handwritten_note', 'tag_or_label'], true);
    }

    private function finishAiPipeline(Asset $asset): void
    {
        if ($this->assetWorkflow->can($asset, WF::TRANSITION_AI_DONE)) {
            $this->assetWorkflow->apply($asset, WF::TRANSITION_AI_DONE);
        }
    }

    private function preferredLocalOcrUrl(Asset $asset): ?string
    {
        $localPath = $this->localImagePath($asset);
        if (is_string($localPath) && $localPath !== '') {
            return 'file://' . $localPath;
        }

        foreach ([$asset->archiveUrl, $asset->originalUrl, $asset->smallUrl] as $candidate) {
            if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_URL)) {
                return $candidate;
            }
        }

        return null;
    }

    private function preferredTriageImageUrl(Asset $asset): ?string
    {
        $localPath = $this->localImagePath($asset);
        if (is_string($localPath) && $localPath !== '') {
            return $localPath;
        }

        ['url' => $url] = $this->preferredFullImageUrl($asset);

        return $url;
    }

    /**
     * Maps ai-tools' claim-v1 envelope entries (predicate/value/confidence/basis) to
     * RawClaim for ClaimIngestor::record(). ai-tools sends confidence as null for
     * most claims (Florence-2 doesn't emit a calibrated score); RawClaim wants an
     * int, so untyped/missing confidence falls back to its own default (100).
     *
     * @param list<array<string, mixed>> $claims
     * @return list<RawClaim>
     */
    private function toRawClaims(array $claims): array
    {
        $rawClaims = [];
        foreach ($claims as $claim) {
            $predicate = $claim['predicate'] ?? null;
            if (!is_string($predicate) || $predicate === '' || !array_key_exists('value', $claim)) {
                continue;
            }

            $confidence = $claim['confidence'] ?? null;
            $basis = $claim['basis'] ?? null;

            $rawClaims[] = new RawClaim(
                $predicate,
                $claim['value'],
                is_numeric($confidence) ? (int) round((float) $confidence) : 100,
                is_string($basis) ? $basis : null,
            );
        }

        return $rawClaims;
    }

    /** @param list<array<string, mixed>> $claims */
    private function firstClaimValue(array $claims, string $predicate): ?string
    {
        foreach ($claims as $claim) {
            if (($claim['predicate'] ?? null) === $predicate && is_scalar($claim['value'] ?? null)) {
                $value = trim((string) $claim['value']);
                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $claims */
    private function longestClaimValue(array $claims, string $predicate): ?string
    {
        $longest = null;
        foreach ($claims as $claim) {
            if (($claim['predicate'] ?? null) !== $predicate || !is_scalar($claim['value'] ?? null)) {
                continue;
            }

            $value = trim((string) $claim['value']);
            if ($value === '') {
                continue;
            }

            if ($longest === null || mb_strlen($value) > mb_strlen($longest)) {
                $longest = $value;
            }
        }

        return $longest;
    }

    /** @param array<string, mixed> $analysis */
    private function localOcrNextTasks(array $analysis): array
    {
        if (!$this->paidAiToolsEnabled) {
            return [];
        }

        $primaryType = strtolower(trim((string) ($analysis['primary_type'] ?? '')));
        $typedLikely = (bool) ($analysis['typed_likely'] ?? false);
        $handwrittenLikely = (bool) ($analysis['handwritten_likely'] ?? false);
        $hasTextLikely = (bool) ($analysis['has_text_likely'] ?? false);

        if (
            $typedLikely
            || $handwrittenLikely
            || $hasTextLikely
            || in_array($primaryType, ['document', 'text_page', 'handwritten_note', 'tag_or_label'], true)
        ) {
            return [AssetAiTask::OCR_MISTRAL->value];
        }

        return [AssetAiTask::ENRICH_FROM_THUMBNAIL->value];
    }

    /** @return array<string, array> */
    private function indexedPriorResults(Asset $asset): array
    {
        $index = [];
        foreach ($asset->aiCompleted as $entry) {
            if (isset($entry['task'], $entry['result']) && is_array($entry['result'])) {
                $index[(string) $entry['task']] = $this->sanitisePriorResult($entry['result']);
            }
        }

        return $index;
    }

    private function sanitisePriorResult(array $result): array
    {
        unset($result['raw_response'], $result['blocks']);

        if (isset($result['text']) && is_string($result['text']) && mb_strlen($result['text']) > 8000) {
            $result['text'] = mb_substr($result['text'], 0, 8000) . "\n[… truncated for context]";
        }

        return $result;
    }

    private function iiifFullUrl(Asset $asset): ?string
    {
        $iiifBase = $asset->iiifManifestEntity?->imageBase ?? $asset->sourceMeta['iiif_base'] ?? null;
        if (!is_string($iiifBase) || $iiifBase === '') {
            return null;
        }

        return rtrim($iiifBase, '/') . '/full/max/0/default.jpg';
    }

    /** @return list<string> */
    private function downloadCandidates(Asset $asset): array
    {
        $candidates = [];
        $iiifBase = $asset->iiifManifestEntity?->imageBase ?? $asset->sourceMeta['iiif_base'] ?? null;

        if (is_string($iiifBase) && $iiifBase !== '' && !$this->looksLikePdf($asset->originalUrl)) {
            $candidates[] = rtrim($iiifBase, '/') . '/full/' . self::CANONICAL_MAX_EDGE . ',/0/default.jpg';
            $candidates[] = rtrim($iiifBase, '/') . '/full/max/0/default.jpg';
        }

        if ($asset->originalUrl !== '') {
            $candidates[] = $asset->originalUrl;
        }

        return array_values(array_unique(array_filter($candidates, static fn(string $url): bool => filter_var($url, FILTER_VALIDATE_URL) !== false)));
    }

    private function looksLikePdf(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        return strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
    }

    private function localImagePath(Asset $asset, bool $preferSmall = false): ?string
    {
        $candidates = $preferSmall
            ? [$asset->localSmallFilename, $asset->localCanonicalFilename]
            : [$asset->localCanonicalFilename, $asset->localSmallFilename];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function persistLocalDerivatives(Asset $asset, string $canonicalInputPath): string
    {
        if (!is_dir($this->canonicalDir) && !mkdir($this->canonicalDir, 0775, true) && !is_dir($this->canonicalDir)) {
            throw new RuntimeException(sprintf('Unable to create canonical dir: %s', $this->canonicalDir));
        }

        $ext = $asset->ext ?: 'bin';
        $canonicalPath = rtrim($this->canonicalDir, '/') . '/' . $asset->id . '.canonical.' . $ext;
        if (!copy($canonicalInputPath, $canonicalPath)) {
            throw new RuntimeException(sprintf('Failed to persist canonical file for asset %s', $asset->id));
        }

        $asset->localCanonicalFilename = $canonicalPath;
        $asset->context ??= [];
        $asset->context['local_canonical_filename'] = $canonicalPath;
        $asset->context['canonical_probe'] = [
            'mime' => $asset->mime,
            'width' => $asset->width,
            'height' => $asset->height,
            'bytes' => filesize($canonicalPath) ?: null,
            'path' => $canonicalPath,
        ];

        if (str_starts_with((string) $asset->mime, 'image/')) {
            $smallPath = rtrim($this->canonicalDir, '/') . '/' . $asset->id . '.small.jpg';
            try {
                $img = new \Imagick($canonicalPath);
                $img->thumbnailImage(1024, 1024, true);
                $img->setImageFormat('jpg');
                $img->setImageCompressionQuality(85);
                $img->writeImage($smallPath);
                $img->clear();
                $asset->localSmallFilename = $smallPath;
                $asset->context['local_small_filename'] = $smallPath;
                $dims = getimagesize($smallPath);
                $asset->context['small_probe'] = [
                    'mime' => 'image/jpeg',
                    'width' => is_array($dims) ? ($dims[0] ?? null) : null,
                    'height' => is_array($dims) ? ($dims[1] ?? null) : null,
                    'bytes' => filesize($smallPath) ?: null,
                    'path' => $smallPath,
                ];
            } catch (\Throwable $e) {
                $this->logger->warning('Failed creating local small derivative for {id}: {error}', [
                    'id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $canonicalPath;
    }

    /** @param array<string,mixed>|null $manifest */
    private function iiifMetadataMap(?array $manifest): array
    {
        if (!is_array($manifest)) {
            return [];
        }

        $metadata = $manifest['metadata'] ?? null;
        if (!is_array($metadata)) {
            return [];
        }

        $map = [];
        foreach ($metadata as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $label = $this->extractIiifLabel($entry['label'] ?? null);
            if ($label === '') {
                continue;
            }

            $values = $this->extractIiifValues($entry['value'] ?? null);
            if ($values === []) {
                continue;
            }

            $key = strtolower($label);
            $map[$key] = array_values(array_unique(array_merge($map[$key] ?? [], $values)));
        }

        return $map;
    }

    private function extractIiifLabel(mixed $label): string
    {
        if (is_string($label)) {
            return trim($label);
        }

        if (!is_array($label)) {
            return '';
        }

        foreach (['none', 'en'] as $lang) {
            $value = $label[$lang] ?? null;
            if (is_array($value) && isset($value[0]) && is_string($value[0])) {
                return trim($value[0]);
            }
        }

        foreach ($label as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_array($value) && isset($value[0]) && is_string($value[0])) {
                return trim($value[0]);
            }
        }

        return '';
    }

    /** @return list<string> */
    private function extractIiifValues(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? [] : [$trimmed];
        }

        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $out = array_merge($out, $this->extractIiifValues($item));
        }

        return array_values(array_unique(array_filter(array_map('trim', $out), static fn(string $v): bool => $v !== '')));
    }

    /** @param array<string,list<string>> $metadataMap */
    private function firstIiifValue(array $metadataMap, array $keys): string
    {
        foreach ($keys as $key) {
            $values = $metadataMap[strtolower($key)] ?? [];
            if ($values !== []) {
                return (string) $values[0];
            }
        }

        return '';
    }

    /** @param array<string,list<string>> $metadataMap
     *  @return list<string>
     */
    private function iiifValues(array $metadataMap, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out = array_merge($out, $metadataMap[strtolower($key)] ?? []);
        }

        return array_values(array_unique(array_filter(array_map('trim', $out), static fn(string $v): bool => $v !== '')));
    }

    private function effectiveArchiveUrl(Asset $asset): ?string
    {
        if (!$asset->storageKey) {
            return $asset->archiveUrl;
        }

        $computed = $this->assetRegistry->s3Url($asset);
        if ($asset->archiveUrl !== $computed) {
            $this->logger->warning('Asset {id} has stale archiveUrl; using computed URL.', [
                'id' => $asset->id,
                'stored_archive_url' => $asset->archiveUrl,
                'computed_archive_url' => $computed,
            ]);
            $asset->archiveUrl = $computed;
        }

        return $computed;
    }

    private function attachDebugContext(
        Asset $asset,
        string $taskName,
        AiTaskInterface $handler,
        array $inputs,
        array $priorResults,
        array $result,
        string $imageSource,
        array $taskOverride = [],
    ): array {
        $storedArchiveUrl = $asset->archiveUrl;
        $archiveUrl = $this->effectiveArchiveUrl($asset);

        $debug = [
            'asset_id' => $asset->id,
            'task' => $taskName,
            'image_url' => $inputs['image_url'] ?? null,
            'image_path' => $inputs['image_path'] ?? null,
            'image_source' => $imageSource,
            'requested_image_preference' => $taskOverride['image_source_preference'] ?? null,
            'requested_model' => $taskOverride['model'] ?? null,
            'tokens' => $result['_tokens'] ?? null,
            'image_candidates' => [
                'preferred_thumbnail_url' => $this->preferredThumbnailUrl($asset)['url'],
                'iiif_thumbnail_url' => $this->iiifThumbnailUrl($asset),
                'iiif_full_url' => $this->iiifFullUrl($asset),
                'small_url' => $asset->smallUrl,
                'archive_url' => $archiveUrl,
                'stored_archive_url' => $storedArchiveUrl,
                'local_canonical_filename' => $asset->localCanonicalFilename,
                'local_small_filename' => $asset->localSmallFilename,
                'original_url' => $asset->originalUrl,
            ],
            'iiif_manifest' => $asset->sourceMeta['iiif_manifest'] ?? null,
            'iiif_base' => $asset->sourceMeta['iiif_base'] ?? null,
            'meta' => $handler->getMeta(),
        ];

        $prompt = $this->renderPromptDebug($taskName, $inputs, $priorResults, $asset->context ?? []);
        if ($prompt !== null) {
            $debug['prompt'] = $prompt;
        }

        $result['_debug'] = $debug;

        return $result;
    }

    /** @return array{system:string,user:string,combined:string}|null */
    private function renderPromptDebug(string $taskName, array $inputs, array $priorResults, array $context): ?array
    {
        $template = "@SurvosAiPipeline/prompt/{$taskName}";
        $templateContext = [
            'imageUrl' => $inputs['image_url'] ?? null,
            'inputs' => $inputs,
            'context' => $context,
            'prior' => $priorResults,
            'ocr_text' => $priorResults['ocr_mistral']['text'] ?? $priorResults['ocr']['text'] ?? null,
            'type' => $priorResults['classify']['type'] ?? null,
            'metadata' => $priorResults['extract_metadata'] ?? [],
            'description' => $priorResults['context_description']['description']
                ?? $priorResults['basic_description']['description']
                ?? null,
            'title' => $priorResults['generate_title']['title'] ?? null,
        ];

        try {
            $systemPrompt = trim($this->twig->render("{$template}/system.html.twig", $templateContext));
            $userPrompt = trim($this->twig->render("{$template}/user.html.twig", $templateContext));

            return [
                'system' => $systemPrompt,
                'user' => $userPrompt,
                'combined' => trim("[SYSTEM]\n{$systemPrompt}\n\n[USER]\n{$userPrompt}"),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * writes the URL locally, esp during debugging but also to check the mime type
     *
     * @param string $url
     * @param string $tempFile
     * @return void
     * @throws TransportExceptionInterface
     */
    private function downloadUrl(string $url, string $tempFile): string
    {
        // if it already exists
        if (file_exists($tempFile) && filesize($tempFile) > 0) {
            return $tempFile;
        }

        if (str_contains($url, 'drive.google.com')) {
            $this->driveService->downloadFileFromUrl(
                $url,
                $tempFile
            );
        } else {
            $client = $this->httpClient;
            $maxAttempts = 4;
            $retryableStatus = [429, 503, 529];

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $this->waitForSourceCooldown();

                $response = $client->request('GET', $url, [
                    'timeout' => $this->serviceHttpTimeoutSeconds,
                ]);
                $code = $response->getStatusCode();
                if ($code !== 200) {
                    if (in_array($code, $retryableStatus, true) && $attempt < $maxAttempts) {
                        if ($code === 529 && $this->source529CooldownMs > 0) {
                            $this->sourceCooldownUntil = microtime(true) + ($this->source529CooldownMs / 1000);
                        }

                        $delaySeconds = (float) (2 ** ($attempt - 1));
                        $jitterMicros = random_int(0, 250000);
                        $this->logger->warning('Source fetch backoff for {url}: HTTP {status} (attempt {attempt}/{max})', [
                            'url' => $url,
                            'status' => $code,
                            'attempt' => $attempt,
                            'max' => $maxAttempts,
                            'cooldown_ms' => $code === 529 ? $this->source529CooldownMs : 0,
                        ]);
                        usleep((int) ($delaySeconds * 1_000_000) + $jitterMicros);
                        continue;
                    }

                    // 404/410 = permanent failure — don't retry, mark as dead
                    if (in_array($code, [404, 410], true)) {
                        throw new \Survos\StateBundle\Exception\UnrecoverableMessageException(
                            "Permanent failure (HTTP {$code}) for {$url} — skipping retries"
                        );
                    }
                    throw new \Exception("Problem with $url " . $code, code: $code);
                }

                $fileHandler = fopen($tempFile, 'w');
                foreach ($client->stream($response) as $chunk) {
                    fwrite($fileHandler, $chunk->getContent());
                }
                fclose($fileHandler);
                break;
            }
        }

        $this->logger->warning("Downloaded url: $url as $tempFile " . filesize($tempFile));
        return $tempFile;

    }

    private function waitForSourceCooldown(): void
    {
        if ($this->sourceCooldownUntil === null) {
            return;
        }

        $remaining = $this->sourceCooldownUntil - microtime(true);
        if ($remaining <= 0) {
            $this->sourceCooldownUntil = null;
            return;
        }

        usleep((int) ($remaining * 1_000_000));
        $this->sourceCooldownUntil = null;
    }

    private function processLocalFile(string $localAbsoluteFilename, Asset $asset): array
    {
        $asset->ext = pathinfo($localAbsoluteFilename, PATHINFO_EXTENSION);
        $mimeType = mime_content_type($localAbsoluteFilename); //

        // Compute content hashes once while file is local.
        $asset->contentHash = hash_file('xxh3', $localAbsoluteFilename);
        $sha256 = hash_file('sha256', $localAbsoluteFilename);
        $asset->context ??= [];
        $asset->context['contentHash'] = $asset->contentHash;
        $asset->context['sha256'] = $sha256;
        // Only process image files for dimensions and exif
        if (str_starts_with($mimeType, 'image/')) {
            [$width, $height, $type, $attr] = getimagesize($localAbsoluteFilename, $info);
            if (!$width) {
                // handled in onComplete
                $this->logger->warning("Invalid temp file $localAbsoluteFilename: $mimeType");
            }

            $asset->width = $width;
            $asset->height = $height;
            $asset->mime = $mimeType;
            $asset->size = filesize($localAbsoluteFilename);

            // problems encoding exif
            // Only read exif for supported image types
            // exif_read_data supports jpeg, tiff, and some webp
            if (in_array($asset->ext, ['jpg', 'jpeg', 'tiff', 'webp'])) {
                try {
                    $exif = @exif_read_data($localAbsoluteFilename, 'IFD0,EXIF,COMPUTED', true);

                     if ($exif !== false) {
                         // clean exif data : remove EXIF key
                         $exif = array_filter($exif, fn($key) => !str_starts_with($key, 'EXIF'), ARRAY_FILTER_USE_KEY);
                         // flatten nested arrays but preserve keys
                         $exif = iterator_to_array(
                             new \RecursiveIteratorIterator(
                                 new \RecursiveArrayIterator($exif)
                             ),
                             true // preserve keys
                         );
                         // normalize values to valid UTF-8 to avoid downstream encoding issues
                         $exif = array_map(static function ($value) {
                             if (is_string($value)) {
                                 // Convert invalid byte sequences to UTF-8, replacing invalid chars
                                 return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                             }
                             return $value;
                         }, $exif);

                         $asset->context ??= [];
                         $asset->context['exif'] = $exif;
                     }
                } catch (\Throwable $e) {
                    $this->logger->warning("EXIF read failed for $localAbsoluteFilename: " . $e->getMessage());
                }
            }
        } else {
            // For non-images, just set mime type and size
            $asset->mime = $mimeType;
            $asset->size = filesize($localAbsoluteFilename);
        }

        return [
            'tempFile' => $localAbsoluteFilename,
            'mimeType' => $mimeType,
            'ext' => $asset->ext
        ];

    }

    private function buildCanonicalAsset(Asset $asset, string $sourcePath): string
    {
        $mime = is_string($asset->mime) ? trim($asset->mime) : '';
        if ($mime === '' || !str_starts_with($mime, 'image/')) {
            return $sourcePath;
        }

        try {
            $image = new \Imagick($sourcePath);

            // Keep animated/multi-frame payloads untouched for now.
            if ($image->getNumberImages() > 1) {
                $image->clear();
                $image->destroy();
                return $sourcePath;
            }

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $maxEdge = max($width, $height);
            if ($maxEdge <= 0) {
                $image->clear();
                $image->destroy();
                return $sourcePath;
            }

            $resized = false;
            if ($maxEdge > self::CANONICAL_MAX_EDGE) {
                $image->resizeImage(
                    self::CANONICAL_MAX_EDGE,
                    self::CANONICAL_MAX_EDGE,
                    \Imagick::FILTER_LANCZOS,
                    1,
                    true
                );
                $resized = true;
            }

            $canonicalPath = $sourcePath . '.canonical.webp';
            $image->stripImage();
            $image->setImageFormat('webp');
            $image->setOption('webp:method', '6');
            $image->setImageCompressionQuality(self::CANONICAL_WEBP_QUALITY);
            $image->writeImage($canonicalPath);
            $image->clear();
            $image->destroy();

            if (!is_file($canonicalPath) || filesize($canonicalPath) === 0) {
                return $sourcePath;
            }

            $sourceSize = filesize($sourcePath) ?: 0;
            $canonicalSize = filesize($canonicalPath) ?: 0;
            if (!$resized && $sourceSize > 0 && $canonicalSize > $sourceSize) {
                @unlink($canonicalPath);
                return $sourcePath;
            }

            $this->processLocalFile($canonicalPath, $asset);
            $asset->ext = 'webp';
            $asset->context ??= [];
            $asset->context['canonical'] = [
                'format' => 'webp',
                'quality' => self::CANONICAL_WEBP_QUALITY,
                'max_edge' => self::CANONICAL_MAX_EDGE,
                'resized' => $resized,
                'bytes' => $canonicalSize,
            ];

            return $canonicalPath;
        } catch (\Throwable $e) {
            $this->logger->warning('Canonical build failed for {id}: {error}', [
                'id' => $asset->id,
                'error' => $e->getMessage(),
            ]);

            return $sourcePath;
        }
    }

    private function resizeForThumbHash(string $imagePath, int $size = 100): array
    {
        $image = new \Imagick($imagePath);

        // Resize to fit within $size x $size, maintaining aspect ratio
        // it's probably the reason analyze is slow, we _could_ call imgProxy with the file
        // but seems like an optimization for later.  We could move it to after archive, too!
        // but now we have the image locally.
        // imgProxy now runs locally too, so this logic may need rethinking.
        $image->thumbnailImage($size, $size, true);

        // 100x100 is okay, this is a oneoff that's not saved.
        // If you need exactly 192x192 with padding/centering:
        // $image->setImageBackgroundColor('transparent');
        // $image->extentImage($size, $size,
        //     -($size - $image->getImageWidth()) / 2,
        //     -($size - $image->getImageHeight()) / 2
        // );
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

    /**
     * Payload construction AND delivery both live in AssetNotifier now — this
     * method's private copy of the body had already drifted from
     * ReplayWebhooksCommand's, and neither sent storageKey or the /info blob.
     *
     * Delivery is asynchronous: this only puts a SendWebhookMessage on the bus, so a
     * subscriber that is slow or down no longer holds up the transition that called it.
     */
    private function fireWebhook(Asset $asset, string $callbackUrl): void
    {
        $this->assetNotifier->notify($asset, $callbackUrl);
    }
}
