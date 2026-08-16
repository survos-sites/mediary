<?php
declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use Survos\MediaBundle\Dto\MediaEnrichment;
use App\Workflow\AssetFlow as WF;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use Survos\FieldBundle\Attribute\Field;
use Survos\FieldBundle\Attribute\RouteIdentity;
use Survos\FieldBundle\Entity\RouteIdentityTrait;
use Survos\FieldBundle\Entity\RouteParametersInterface;
use Survos\FieldBundle\Enum\Widget;
use Survos\MeiliBundle\Metadata\Fields;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Survos\MediaBundle\Util\MediaIdentity;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: \App\Repository\AssetRepository::class)]
#[ORM\Table]
#[ORM\Index(name: 'idx_asset_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_asset_backend', columns: ['storage_backend'])]
#[ORM\Index(name: 'idx_asset_media_record', columns: ['media_record_id'])]
// (facetColumn, id) covering indexes removed: survos/search-bundle now emits plain
// count(col) for base-entity facets (countDistinct:false) and facets run through
// Meilisearch — so the PK-covering workaround for the old Mezcalito full-sort
// (mezcalito/ux-search#46) is obsolete. Re-add a plain single-column index only
// if a specific postgres query is proven to need it.
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new GetCollection()
    ],
    // NOT exposed over MCP yet. API Platform 4.3's experimental `mcp:` support does
    // register the tool and route tools/call to it, but its ObjectMapper path tries to
    // *instantiate* this entity to build the schema, and Asset::__construct requires
    // $originalUrl -- so every call fails with:
    //   "Argument #1 ($originalUrl) must be of type string, null given"
    // Verified against a live /_mcp session on 2026-08-16. Declaring a tool that always
    // errors is worse than declaring none, so this waits for either a read-only output
    // DTO or a fix upstream. The endpoint itself works; see config/packages/mcp.yaml.
)]
#[MeiliIndex(
    autoIndex: false, // disabled 2026-07-24: per-transition flush → per-transition dispatch was flooding the meili doctrine:// transport at 15K+ assets; re-enable once dispatch is batched to terminal states only
    chats: ['meili_assistant'],
    sortable: ['createdAt', 'aiTokensTotal', 'size', 'width', 'height', 'faceCount'],
    filterable: ['provider', 'dataset', 'mime', 'clients', 'marking',
        'ext', 'type', 'publisher', 'reuse',
//        'aiDocumentType', 'aiDocumentSubtype',
        'subjects', 'classification', 'objectIdentifiers', 'faceCount',
//                 'aiKeywords', 'aiPeople', 'aiPlaces', 'aiOrganisations', 'aiSafety'
    ],
    searchable: ['title', 'description', 'filename', 'subjects', 'classification', 'objectIdentifiers', 'publisher', 'aiTitle', 'aiDescription', 'aiOcrText', 'aiKeywords',
                  'aiPeople', 'aiPlaces', 'aiSubjects'],
    persisted: new Fields(
        groups: ['asset.read'],
        fields: ['id', 'provider', 'dataset',
            'originalUrl', 'archiveUrl',
        'mime', 'ext', 'filename', 'type', 'reuse', 'publisher', 'subjects', 'classification', 'objectIdentifiers', 'objectIdentifierConfidences',
        'size', 'width', 'height',
        'title', 'description', 'thumb', 'smallUrl',
        'createdAt', 'marking', 'mediaRecordId', 'storageKey',
                  'aiDocumentType'],
    ),
    prompts: [
        'system' => 'You are assisting with media assets. Always use tool-backed search results from this index and always include [id:{value}] where {value} is the Asset primary key field {{ primaryKey }}.',
    ],
    ui: ['columns' => 4, 'cardClass' => 'asset-card'],
)]
// Route identity: the 16-hex `id` is the whole URL key (erp.entityId → id), so
// menus, link helpers and the state-bundle workflow component can address assets.
#[RouteIdentity(field: 'id')]
class Asset implements MarkingInterface, RouteParametersInterface, \Stringable
{
    use MarkingTrait; // provides $marking + getters/setters compatible with the workflow engine
    use RouteIdentityTrait;

    /** Primary key: 16-char lowercase hex xxh3(originalUrl). */
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 16)]
    #[Field(searchable: true, sortable: true, order: 10, width: '8rem')]
    public string $id {
        set {
            if (\strlen($value) !== 16) {
                throw new \InvalidArgumentException('asset id must be exactly 16 hex chars (xxh3(originalUrl)). ' . $value);
            }
            $this->id = $value;
        }
    }

    /**
     * Aggregator/provider (e.g. "mus", "dc") — the first segment of the dataset
     * key, derived on ingest. A coarser facet than dataset for ux-search.
     */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    #[Field(searchable: true, sortable: true, order: 11, width: '8rem')]
    public ?string $provider = null;

    /**
     * Dataset key (provider/code, e.g. "mus/fortepan") this asset belongs to,
     * supplied per-row via media:sync context. This is the claim/vault scope —
     * AI claims persist under the dataset-aware path keyed by this value.
     */
    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    #[Field(searchable: true, sortable: true, order: 12, width: '10rem')]
    public ?string $dataset = null;

    #[Groups(['asset.read'])]
    #[Field(searchable: true, sortable: true, order: 30, width: '24rem', group: 'Content')]
    public ?string $title { get => $this->sourceMeta['dcterms:title'] ?? null; }
    #[Groups(['asset.read'])]
    #[Field(searchable: true, visible: false, order: 35, group: 'Content')]
    public ?string $description { get => $this->sourceMeta['dcterms:description'] ?? null; }
    #[Groups(['asset.read'])]
    #[Field(searchable: true, filterable: true, widget: Widget::Select, facet: true, visible: false, order: 70, group: 'Content')]
    public ?array $subjects { get => $this->sourceMeta['dcterms:subject'] ?? $this->sourceMeta['iiif_subjects'] ?? null; }

    /**
     * imgproxy /info classification labels (e.g. "Person", "Dress").
     *
     * Promoted from the cached /info blob so Doctrine and Meili can facet it.
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    #[Groups(['asset.read'])]
    #[Field(searchable: true, filterable: true, widget: Widget::Select, facet: true, order: 72, group: 'Content')]
    public ?array $classification = null;

    /**
     * imgproxy object identifier labels promoted for faceting.
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    #[Groups(['asset.read'])]
    #[Field(searchable: true, filterable: true, widget: Widget::Select, facet: true, order: 73, group: 'Content')]
    public ?array $objectIdentifiers = null;

    /** Confidence scores keyed by object identifier label. */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    #[Groups(['asset.read'])]
    public ?array $objectIdentifierConfidences = null;

    /**
     * Count of face boxes from imgproxy's detect_objects (face-detection model).
     * A free, built-in way to facet portrait (1) / couple (2) / small group (3-5) /
     * large group (6+) without any extra analysis.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['asset.read'])]
    #[Field(sortable: true, filterable: true, facet: true, widget: Widget::Range, order: 74, group: 'Content')]
    public ?int $faceCount = null;

    // ── Claims denormalized for SQL full-text search ──────────────────────────
    // The AI-generated title/description/keywords a user actually searches by
    // (and the search hit card displays via asset_meta()) live in the separate
    // claims store, not on this entity — see AssetClaimsExtension. These columns
    // mirror the same claims (kept in sync by ClaimSearchSync) purely so
    // AssetSearch's tsvector can index them; nothing here should be read
    // directly — use asset_meta() for display.

    /** Mirrors ClaimMetaResolver::resolve()['caption'] (ai:caption / observe:caption / dcterms:title). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $claimCaption = null;

    /** Mirrors ClaimMetaResolver::resolve()['prose'] (ai:observationProse / observe:description / ai:denseSummary). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $claimProse = null;

    /** Mirrors ClaimMetaResolver::resolve()['subjects'] (dcterms:subject + observe:tag). */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $claimSubjects = null;

    /** Mirrors ClaimMetaResolver::resolve()['type'] (dcterms:type / observe:classification). */
    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    public ?string $claimType = null;

    #[Groups(['asset.read'])]
    #[Field(filterable: true, widget: Widget::Select, facet: true, order: 60, group: 'Content')]
    public ?string $type { get => $this->sourceMeta['dcterms:type'] ?? null; }

    #[Groups(['asset.read'])]
    #[Field(filterable: true, widget: Widget::Select, facet: true, visible: false, order: 80, group: 'Rights')]
    public ?string $reuse { get => $this->sourceMeta['reuse_allowed'] ?? null; }

    #[Groups(['asset.read'])]
    #[Field(order: 20, width: '88px')]
    public ?string $thumb {
        get => $this->smallUrl
            ?? $this->iiifManifestEntity?->thumbnailUrl
            ?? $this->sourceMeta['thumbnail_url']
            ?? null;
    }

    #[Groups(['asset.read'])]
    #[Field(filterable: true, widget: Widget::Select, facet: true, visible: false, order: 90, group: 'Source')]
    public ?string $publisher { get => $this->sourceMeta['dcterms:publisher'] ?? null; }

    #[Groups(['asset.read'])]
    #[Field(searchable: true, visible: false, order: 100, group: 'Source')]
    public ?string $filename {
        get {
            $path = (string) (parse_url($this->originalUrl, PHP_URL_PATH) ?? '');
            $name = basename($path);
            return $name !== '' ? $name : null;
        }
    }

    #[Groups(['asset.read'])]
    #[Field(filterable: true, widget: Widget::Select, facet: true, visible: false, order: 110, group: 'Source')]
    public ?string $mediaRecordId { get => $this->mediaRecord?->id; }

    /** Fast non-cryptographic content hash (xxh3 of bytes). */
    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    public ?string $contentHash = null;

    /** HTTP status from last fetch (used by guards); 200 = OK. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    public ?int $statusCode = null;

    public ?int $sizeInMegabytes { get => $this->size ? (int)($this->size / (1024*1024)) : null;}

    /** Original MIME type (image/*, audio/*, video/*). */
    #[ORM\Column(type: Types::STRING, nullable: true)]
    #[Groups(['asset.read'])]
    #[Field(filterable: true, widget: Widget::Select, facet: true, order: 50, group: 'File')]
    public ?string $mime = null;

    /** Bytes of original file. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    #[Groups(['asset.read'])]
    #[Field(sortable: true, filterable: true, widget: Widget::Range, visible: false, order: 130, group: 'File')]
    public ?int $size = null {
        set {
            if ($value !== null && $value < 0) {
                throw new \InvalidArgumentException('size must be >= 0 or null');
            }
            $this->size = $value;
        }
    }

    /** Dimensions (for images/videos when known). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['asset.read'])]
    #[Field(sortable: true, filterable: true, widget: Widget::Range, visible: false, order: 140, group: 'Dimensions')]
    public ?int $width = null {
        set {
            if ($value !== null && $value < 0) {
                throw new \InvalidArgumentException('width must be >= 0 or null');
            }
            $this->width = $value;
        }
    }

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['asset.read'])]
    #[Field(sortable: true, filterable: true, widget: Widget::Range, visible: false, order: 150, group: 'Dimensions')]
    public ?int $height = null {
        set {
            if ($value !== null && $value < 0) {
                throw new \InvalidArgumentException('height must be >= 0 or null');
            }
            $this->height = $value;
        }
    }

    /**
     * Image-derived analysis data (OCR text, thumbhash, colors, phash, sha256).
     * Written by AssetWorkflow after download. Never overwritten by client metadata.
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['asset.read'])]
    public ?array $context = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['asset.read'])]
    public ?string $localOcrText = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    #[Groups(['asset.read'])]
    public ?float $localOcrConfidence = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    #[Groups(['asset.read'])]
    public ?string $localOcrPrimaryType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['asset.read'])]
    public ?string $localOcrSourceUrl = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    #[Groups(['asset.read'])]
    public ?string $localOcrProvider = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    #[Groups(['asset.read'])]
    public ?string $localOcrModel = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['asset.read'])]
    public ?\DateTimeImmutable $localOcrAt = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['asset.read'])]
    public ?int $localOcrStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['asset.read'])]
    public ?string $localOcrError = null;

    /**
     * Source metadata from the originating aggregator — dcterms:* keyed JSONB.
     * Written by BatchController from client context hints (DC fields, rights, ARK, IIIF URLs).
     * Never overwritten by image analysis.
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['asset.read'])]
    public ?array $sourceMeta = null;

    #[ORM\ManyToOne(targetEntity: IiifManifest::class, inversedBy: 'assets')]
    #[ORM\JoinColumn(name: 'iiif_manifest_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public ?IiifManifest $iiifManifestEntity = null;

    #[ORM\ManyToOne(targetEntity: MediaRecord::class, inversedBy: 'assets')]
    #[ORM\JoinColumn(name: 'media_record_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public ?MediaRecord $mediaRecord = null;

     /**
      * Immutable parent reference (xxh3 key of parent Asset).
      * Null for top-level assets (e.g. PDFs).
      */
     #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
     public ?string $parentKey = null;

     /**
      * Total number of derived child assets.
      * Includes pages, OCR, and any other derivatives.
      */
     #[ORM\Column(type: Types::INTEGER)]
     public int $childCount = 0;

     /**
      * 1-based page number for page assets.
      * Null for non-page assets.
      */
     #[ORM\Column(type: Types::INTEGER, nullable: true)]
     public ?int $pageNumber = null;

     /**
      * Denormalized indicator that OCR exists for THIS asset.
      * True means at least one OCR-derived Asset exists whose parentKey equals this asset's key.
      */
     #[ORM\Column(type: Types::BOOLEAN)]
     public bool $hasOcr = false;

     // ─────────────── AI task pipeline ───────────────

     /**
      * Ordered list of AI task names still to be executed.
      * Example: ["classify", "extract_metadata", "generate_title"]
      * A worker picks the first entry, runs it, then moves it to aiCompleted.
      */
     #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
     public array $aiQueue = [];

     /**
      * History of completed AI tasks.
      * Each entry: { task: string, at: ISO-8601 string, result: mixed }
      */
     #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
     public array $aiCompleted = [];

     /**
      * Normalized aggregate built from aiCompleted for display/indexing.
      */
     #[ORM\Column(type: Types::JSON, nullable: true)]
     #[Groups(['asset.read'])]
     public ?array $mediaEnrichment = null;

     /** Cached DTO view of mediaEnrichment (not persisted). */
     public ?MediaEnrichment $enriched = null;

     /**
      * When true the AI worker skips this asset entirely.
      * Lets an operator pause processing (e.g. while reviewing results).
      */
     #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
     public bool $aiLocked = false;

     // ── AI classification — kept as a real column for SQL WHERE in browse ────
     // All other AI result fields (title, description, OCR text, keywords, etc.)
     // are computed at normalisation time by AssetNormalizer from aiCompleted.

     /** Classified document/object type — stored for SQL filtering only. */
     #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
     public ?string $aiDocumentType = null;

     /** Client codes referencing this asset (additive). */
     #[ORM\Column(type: Types::JSON)]
     public array $clients = [];

    /** Storage path/key of ORIGINAL (e.g., o/ab/cd/<hash>.<ext>). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $storageBackend = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['asset.read'])]
    public ?string $storageKey = null;

    /** URL of archived original (object storage) */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['asset.read'])] // for now, maybe removed after debugging
    #[Field(visible: false, order: 210, group: 'Storage')]
    public ?string $archiveUrl = null;

    private ?string $smallUrlOverride = null;

    #[Groups(['asset.read'])]
    #[Field(visible: false, order: 25, group: 'File')]
    public ?string $smallUrl {
        get => $this->smallUrlOverride
            ?? $this->iiifManifestEntity?->thumbnailUrl
            ?? $this->sourceMeta['thumbnail_url']
            ?? null;

        set(?string $value) {
            $this->smallUrlOverride = $value;
        }
    }

    /** Persisted local canonical path for shared AI-tools access. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $localCanonicalFilename = null;

    /** Persisted local small derivative path for CPU tools (pHash/thumb tasks). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $localSmallFilename = null;

    /** Optional original extension hint (jpg, mp4, …). */
    #[ORM\Column(type: Types::STRING, length: 12, nullable: true)]
    #[Groups(['asset.read'])]
    #[Field(filterable: true, widget: Widget::Select, facet: true, order: 55, group: 'File')]
    public ?string $ext = null;

    /** Ingest timestamp. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['asset.read'])]
    #[Field(sortable: true, filterable: true, widget: Widget::Date, order: 170, format: 'datetime', group: 'Workflow')]
    public \DateTimeImmutable $createdAt;

    /** Soft delete. */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    public ?\DateTime $deletedAt = null;

    public int $resizedCount { get => count($this->resized??[]); }
    public string $path { get => $this->id . "." . $this->ext; }


    public function __construct(
        /** Source/original URL (for provenance / retries). */
        #[ORM\Column(type: Types::TEXT, nullable: false)]
        public string $originalUrl
    )
    {
        $this->id          = MediaIdentity::idFromOriginalUrl($this->originalUrl);
        $this->createdAt   = new \DateTimeImmutable();
        $this->marking     = WF::PLACE_NEW; // seed initial marking via workflow constant
    }


//    /** Convenience: 32-char lowercase hex of PK. */
//    public function contentHashHex(): string
//    {
//        return \bin2hex($this->contentHash);
//    }

    public function __toString()
    {
        return $this->id;
    }

    // ── Computed AI accessors (read from aiCompleted — no DB columns) ─────────
    // Used by Twig templates and anywhere that needs AI results without going
    // through the serializer. The normalizer uses its own expandAiCompleted()
    // for Meilisearch/API output; these are the entity-side equivalents.

    /** @return array<string, mixed>  last successful result per task, keyed by task name */
    public function aiResults(): array
    {
        $completed = $this->aiCompleted;
        if ($completed === []
            && isset($this->context['aiTaskResults'])
            && is_array($this->context['aiTaskResults'])
        ) {
            $completed = $this->context['aiTaskResults'];
        }

        $byTask = [];
        foreach ($completed as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $task = $entry['task'] ?? null;
            $result = $entry['result'] ?? null;
            if (!is_string($task) || !is_array($result)) {
                continue;
            }

            if (empty($result['failed']) && empty($result['skipped'])) {
                $byTask[$task] = $this->normalizeTaskResult($task, $result);
            }
        }

        return $byTask;
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function normalizeTaskResult(string $task, array $result): array
    {
        if ($task !== 'enrich_from_thumbnail') {
            return $result;
        }

        $speculations = $result['speculations'] ?? null;
        if (!is_array($speculations)) {
            return $result;
        }

        $normalized = [];
        foreach ($speculations as $speculation) {
            if (is_array($speculation)) {
                $normalized[] = $speculation;
                continue;
            }

            if (!is_string($speculation) || trim($speculation) === '') {
                continue;
            }

            $decoded = json_decode($speculation, true);
            if (is_array($decoded)) {
                $normalized[] = $decoded;
                continue;
            }

            $normalized[] = ['claim' => $speculation];
        }

        $result['speculations'] = $normalized;

        return $result;
    }

    public function getAiTitle(): ?string
    {
        if (is_array($this->mediaEnrichment) && isset($this->mediaEnrichment['title'])) {
            return is_string($this->mediaEnrichment['title']) ? $this->mediaEnrichment['title'] : null;
        }

        $r = $this->aiResults();
        return $r['generate_title']['title'] ?? null;
    }

    public function getAiDescription(): ?string
    {
        if (is_array($this->mediaEnrichment) && isset($this->mediaEnrichment['description'])) {
            return is_string($this->mediaEnrichment['description']) ? $this->mediaEnrichment['description'] : null;
        }

        $r = $this->aiResults();
        return $r['context_description']['description']
            ?? $r['basic_description']['description']
            ?? null;
    }

    public function getAiOcrText(): ?string
    {
        if (is_string($this->localOcrText) && trim($this->localOcrText) !== '') {
            return $this->localOcrText;
        }

        if (is_array($this->mediaEnrichment) && isset($this->mediaEnrichment['ocrText'])) {
            return is_string($this->mediaEnrichment['ocrText']) ? $this->mediaEnrichment['ocrText'] : null;
        }

        $r = $this->aiResults();
        return $r['ocr_mistral']['text'] ?? $r['ocr']['text'] ?? $r['transcribe_handwriting']['text'] ?? null;
    }

    /** @return string[] */
    public function getAiKeywords(): array
    {
        if (is_array($this->mediaEnrichment) && isset($this->mediaEnrichment['keywords']) && is_array($this->mediaEnrichment['keywords'])) {
            return array_values(array_filter($this->mediaEnrichment['keywords'], static fn ($v): bool => is_string($v) && $v !== ''));
        }

        return $this->aiResults()['keywords']['keywords'] ?? [];
    }

    /** @return string[] */
    public function getAiPeople(): array
    {
        if (is_array($this->mediaEnrichment) && isset($this->mediaEnrichment['people']) && is_array($this->mediaEnrichment['people'])) {
            return array_values(array_filter($this->mediaEnrichment['people'], static fn ($v): bool => is_string($v) && $v !== ''));
        }

        $r = $this->aiResults();
        return $r['people_and_places']['people'] ?? $r['extract_metadata']['people'] ?? [];
    }

    /** @return string[] */
    public function getAiPlaces(): array
    {
        if (is_array($this->mediaEnrichment) && isset($this->mediaEnrichment['places']) && is_array($this->mediaEnrichment['places'])) {
            return array_values(array_filter($this->mediaEnrichment['places'], static fn ($v): bool => is_string($v) && $v !== ''));
        }

        $r = $this->aiResults();
        return $r['people_and_places']['places'] ?? $r['extract_metadata']['places'] ?? [];
    }

    /** @return string[] */
    public function getAiOrganisations(): array
    {
        if (is_array($this->mediaEnrichment) && isset($this->mediaEnrichment['organisations']) && is_array($this->mediaEnrichment['organisations'])) {
            return array_values(array_filter($this->mediaEnrichment['organisations'], static fn ($v): bool => is_string($v) && $v !== ''));
        }

        $r = $this->aiResults();
        return array_values(array_unique(array_merge(
            $r['people_and_places']['organisations'] ?? [],
            $r['extract_metadata']['organisations'] ?? [],
        )));
    }

    public function getAiDateRange(): ?string
    {
        if (is_array($this->mediaEnrichment) && isset($this->mediaEnrichment['dateRange'])) {
            return is_string($this->mediaEnrichment['dateRange']) ? $this->mediaEnrichment['dateRange'] : null;
        }

        return $this->aiResults()['extract_metadata']['dateRange'] ?? null;
    }

    public function getAiSummary(): ?string
    {
        if (is_array($this->mediaEnrichment)) {
            if (isset($this->mediaEnrichment['summary']) && is_string($this->mediaEnrichment['summary'])) {
                return $this->mediaEnrichment['summary'];
            }

            if (isset($this->mediaEnrichment['denseSummary']) && is_string($this->mediaEnrichment['denseSummary'])) {
                return $this->mediaEnrichment['denseSummary'];
            }
        }

        return $this->aiResults()['summarize']['summary'] ?? null;
    }

    public function getAiDocumentSubtype(): ?string
    {
        if (is_array($this->mediaEnrichment) && isset($this->mediaEnrichment['documentSubtype'])) {
            return is_string($this->mediaEnrichment['documentSubtype']) ? $this->mediaEnrichment['documentSubtype'] : null;
        }

        return $this->aiResults()['classify']['subtype'] ?? null;
    }

    public function getMediaEnrichmentDto(): ?MediaEnrichment
    {
        if ($this->enriched instanceof MediaEnrichment) {
            return $this->enriched;
        }

        if (!is_array($this->mediaEnrichment)) {
            return null;
        }

        $this->enriched = MediaEnrichment::fromArray($this->mediaEnrichment);
        return $this->enriched;
    }

    #[ORM\PostLoad]
    public function hydrateEnriched(): void
    {
        $this->enriched = is_array($this->mediaEnrichment)
            ? MediaEnrichment::fromArray($this->mediaEnrichment)
            : null;
    }

    /**
     * Get the enrich_from_thumbnail result as a typed DTO.
     * Returns null if that task hasn't run yet.
     */
    public function getEnrichFromThumbnail(): ?\Survos\AiWorkflowBundle\Result\EnrichFromThumbnailResult
    {
        $data = $this->aiResults()['enrich_from_thumbnail'] ?? null;
        if (!is_array($data)) return null;

        return new \Survos\AiWorkflowBundle\Result\EnrichFromThumbnailResult(
            title:           $data['title']         ?? null,
            description:     $data['description']   ?? null,
            keywords:        $data['keywords']       ?? [],
            people:          $data['people']         ?? [],
            places:          $data['places']         ?? [],
            contentType:     $data['content_type']   ?? null,
            dateHint:        $data['date_hint']      ?? null,
            hasText:         (bool)($data['has_text'] ?? false),
            denseSummary:    $data['dense_summary']  ?? null,
            confidence:      (float)($data['confidence'] ?? 1.0),
            speculations:    $data['speculations']   ?? [],
        );
    }

    /**
     * Task classification for display grouping:
     *   ocr   → OCR tab (view image + text together)
     *   image → AI Metadata tab (visual analysis)
     */
    public static function taskGroup(string $taskName): string
    {
        return match($taskName) {
            'ocr', 'ocr_mistral', 'transcribe_handwriting', 'annotate_handwriting', 'layout'
                => 'ocr',
            default
                => 'image',
        };
    }

    /** Total tokens spent across all completed tasks. */
    public function getAiTokensTotal(): int
    {
        $total = 0;
        foreach ($this->aiCompleted as $entry) {
            $total += $entry['result']['_tokens']['total'] ?? 0;
        }
        return $total;
    }
}
