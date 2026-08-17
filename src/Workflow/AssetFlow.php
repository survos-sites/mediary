<?php
declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Asset;
use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Transition;
use Survos\StateBundle\Attribute\Workflow;

/**
 * SAIS Asset workflow — images, audio, video.
 * Analysis happens BEFORE archive to allow stamping object metadata at create time.
 * Guards use Symfony ExpressionLanguage (subject = Asset).
 */
#[Workflow(supports: [Asset::class], name: self::WORKFLOW_NAME)]
class AssetFlow
{
    public const WORKFLOW_NAME = 'asset';

    // ─────────────── Places ───────────────

    #[Place(
        initial: true,
        info: 'Registered/added',
        next: [self::TRANSITION_FETCH_IIIF, self::TRANSITION_ARCHIVE]
    )]
    public const PLACE_NEW = 'new';

    #[Place(
        info: 'Registered/added',
        next: [self::TRANSITION_ARCHIVE, self::TRANSITION_QUEUE_AI]
    )]
    public const PLACE_IIIF = 'iiif';

    #[Place(
        info: 'Source master streamed into our S3 (museado). imgproxy reads it via s3://.',
        next: [self::TRANSITION_INFO]
    )]
    public const PLACE_ARCHIVED = 'archived';

    #[Place(
        info: 'imgproxy /info fetched: dimensions, byte size, format, hash from s3:// source.',
        // classify:5 (weak on archival photography) is dropped for good — argus's
        // Florence-2 triage is the source of truth for tags/descriptions now. But
        // triage is NOT in the auto-cascade: at ~30s/image (single CPU instance, no
        // horizontal scaling) it's fine for manual spot-checks (state:iterate -t triage)
        // but too slow to be part of the practical default pipeline. Bulk observe goes
        // through media:batch-observe (OpenAI Batch API) instead. Revisit if/when
        // argus triage becomes a proper AI task with its own scale story.
        next: [self::TRANSITION_QUEUE_AI]
    )]
    public const PLACE_INFORMED = 'informed';

    #[Place(
        info: 'Triage observations recorded (caption, ocr_text, keywords from ai-tools /v1/responses)',
        next: [self::TRANSITION_ANALYZE]
    )]
    public const PLACE_TRIAGED = 'triaged';

    #[Place(
        info: 'Ocr/confidence from local Tesseract',
//        next: [self::TRANSITION_ANALYZE]
    )]
    public const PLACE_LOCAL_OCR = 'local_ocr';

    //    #[Place(
//        info: 'Basic checks passed (type/size/codec)',
//        next: [self::TRANSITION_ANALYZE]
//    )]
//    public const PLACE_VALIDATED = 'validated';

    #[Place(
        info: 'Features computed (blurhash/palette/pHash/probe)',
//        next: [self::TRANSITION_ARCHIVE]
    )]
    public const PLACE_ANALYZED = 'analyzed';

     #[Place(info: 'All done')]
     public const PLACE_COMPLETE = 'complete';

    #[Place(
        info: 'AI task pipeline active — aiQueue has pending tasks',
        next: [self::TRANSITION_AI_TASK]
    )]
    public const PLACE_AI_READY = 'ai_ready';

    #[Place(info: 'Terminal error / exhausted retries')]
    public const PLACE_FAILED = 'failed';

    #[Place(info: 'Asset + its archive object deleted (terminal). Set immediately before row removal.')]
    public const PLACE_DELETED = 'deleted';

    // ───────────── Transitions ─────────────

    #[Transition(
        from: [self::PLACE_NEW, self::PLACE_IIIF, self::PLACE_ARCHIVED, self::PLACE_FAILED],
        to: self::PLACE_ARCHIVED,
        info: 'Archive',
        description: 'Stream the source master straight into our S3 (museado) so imgproxy reads it via s3://. No local processing.',
        async: true,
        // `next` lives on PLACE_ARCHIVED, NOT here. The Entered listener fires
        // with the subject already in the new place (correct marking, which
        // matters in sync mode); duplicating it here dispatched /info twice.
    )]
    public const TRANSITION_ARCHIVE = 'archive';

    #[Transition(
        from: [self::PLACE_ARCHIVED, self::PLACE_INFORMED, self::PLACE_FAILED],
        to: self::PLACE_INFORMED,
        info: 'Info',
        description: 'Call imgproxy PRO /info against the s3:// source: dimensions, byte size, format, thumbhash. No download.',
        async: true,
        // note: this goes here and NOT in place, because PlaceEntered is called BEFORE onCompleted
        next: [self::TRANSITION_INFO_FAILED]
    )]
    public const TRANSITION_INFO = 'info';

    /**
     * Hard delete: remove the archived image from storage, then delete the asset
     * row. Use to purge bad masters (e.g. thumbnail-resolution archives). Run async.
     * Irreversible — the s3 object and the DB record are both gone.
     */
    #[Transition(
        from: [self::PLACE_ARCHIVED, self::PLACE_INFORMED],
        to: self::PLACE_DELETED,
        info: 'Delete',
        description: 'Delete the archive.storage object (if present), then delete the asset record. Irreversible.',
        async: true,
    )]
    public const TRANSITION_DELETE = '_delete';

    #[Transition(
        from: [self::PLACE_INFORMED, self::PLACE_LOCAL_OCR],
        to: self::PLACE_AI_READY,
        info: 'Local OCR',
        description: 'Run local OCR confidence pass and queue follow-up AI tasks',
        async: true,
        next: [self::TRANSITION_AI_TASK]
    )]
    public const TRANSITION_LOCAL_OCR = 'local_ocr';

    #[Transition(
        from: [self::PLACE_NEW, self::PLACE_IIIF],
        to: self::PLACE_IIIF,
        info: 'Fetch IIIF manifest',
        description: 'Fetch IIIF metadata, then archive the selected source master to S3',
        async: true,
        // `next` lives on PLACE_IIIF, NOT here — the same rule TRANSITION_ARCHIVE
        // documents above. Declaring it in both places dispatched archive TWICE
        // per asset: measured 2026-08-17, 85 assets through this transition
        // produced 170 asset.archive messages. onArchive() dedups the UPLOAD on
        // content hash, so the second one is invisible in S3 — but it still
        // streams the whole master from the origin to compute that hash, which
        // is exactly the redundant origin traffic mediary#7 exists to stop.
    )]
    public const TRANSITION_FETCH_IIIF = 'iiif';

    #[Transition(
        from: self::PLACE_INFORMED,
        to: self::PLACE_FAILED,
        info: 'Info failed',
        description: 'imgproxy /info did not return usable source metadata; may be retried with backoff',
        guard: "subject.statusCode !== 200",
        async: false
    )]
    public const TRANSITION_INFO_FAILED = 'info_failed';

//    #[Transition(
//        from: self::PLACE_INFORMED,
//        to: self::PLACE_VALIDATED,
//        info: 'Validate',
//        description: 'Check type/codec/dimensions/size; normalize extension',
//        guard: "subject.statusCode === 200 and subject.mime matches '/^(image|audio|video)/'",
//        async: false,
//        next: [self::TRANSITION_ANALYZE]
//    )]
//    public const TRANSITION_VALIDATE = 'validate';
//
    #[Transition(
        from: self::PLACE_INFORMED,
        to: self::PLACE_FAILED,
        info: 'Invalid file',
        description: 'Unsupported media type or invalid attributes',
        guard: "subject.statusCode === 200 and not (subject.mime matches '/^(image|audio|video)/')",
        async: false,
    )]
    public const TRANSITION_INVALID = 'invalid_file';

    /**
     * Triage = FREE local observation. Calls ai-tools /v1/responses with model=auto:
     * Florence-2 (caption + ocr_text + dense region tags) plus Tesseract for
     * dense documents. Result is an Observation[] envelope persisted on the asset.
     *
     * Distinct from {@see TRANSITION_ANALYZE}: triage extracts media-derived
     * facts (free, local Python). Analyze computes mathematical visual features
     * (blurhash/palette/pHash/probe — also free, local C/PHP).
     *
     * Paid VLM dispatch USED to live somewhere around here; it has been
     * relocated entirely to consumer applications, which now operate text-only
     * on triage's Observation[] output. Do not reintroduce vision-LLM calls
     * into either triage or analyze without a deliberate scope discussion.
     */
    #[Transition(
        from: self::PLACE_INFORMED,
        to: self::PLACE_TRIAGED,
        info: 'Triage',
        description: 'Call ai-tools /v1/responses model=auto; persist Observation[] (caption, ocr_text, keywords).',
        async: true,
        next: [self::TRANSITION_ANALYZE],
    )]
    public const TRANSITION_TRIAGE = 'triage';

    #[Transition(
        from: [self::PLACE_INFORMED, self::PLACE_TRIAGED],
        to: self::PLACE_ANALYZED,
        info: 'Analyze',
        description: 'Compute blurhash/thumbhash, color palette, pHash, media probe',
        async: true,
//        next: [self::TRANSITION_ARCHIVE]
    )]
    public const TRANSITION_ANALYZE = 'analyze';

//     #[Transition(
//         from: [self::PLACE_INFORMED],
//         to: self::PLACE_ARCHIVED,
//         info: 'Archive original',
//         description: 'Stub: archive original to durable storage (S3)',
//         next: [self::TRANSITION_ARCHIVE]
////         async: true
//     )]
//     public const TRANSITION_ARCHIVE = 'archive';

     #[Transition(
         from: self::PLACE_ANALYZED,
         to: self::PLACE_COMPLETE,
         info: 'Finalize',
         description: 'Finalize asset (indexing handled by listeners)'
     )]
     public const TRANSITION_FINALIZE = 'finalize';

    /**
     * Enqueue AI tasks — moves asset into the AI pipeline.
     * Caller must populate aiQueue before applying this transition.
     * Allowed from complete or analyzed so tasks can be added/re-added at any time.
     */
    #[Transition(
        from: [self::PLACE_COMPLETE, self::PLACE_INFORMED, self::PLACE_AI_READY, self::PLACE_NEW],
        to: self::PLACE_AI_READY,
        info: 'Queue AI tasks',
        description: 'Populate aiQueue and enter the AI task pipeline',
        guard: "subject.aiQueue != [] and not subject.aiLocked",
    )]
    public const TRANSITION_QUEUE_AI = 'queue_ai';

    /**
     * Run next AI task — picks the first item off aiQueue, executes it,
     * appends result to aiCompleted.
     *
     * Loops back to ai_ready when more tasks remain; caller must apply
     * TRANSITION_FINALIZE afterward when queue is empty.
     *
     * Worker must check aiLocked before applying this transition.
     */
    #[Transition(
        from: self::PLACE_AI_READY,
        to: self::PLACE_AI_READY,
        info: 'Run next AI task',
        description: 'Execute the next task in aiQueue and record the result in aiCompleted',
        guard: "subject.aiQueue != [] and not subject.aiLocked",
        async: true,
    )]
    public const TRANSITION_AI_TASK = 'ai_task';

    /**
     * Complete AI pipeline — applied by the worker after the last task in
     * aiQueue is consumed and aiQueue is empty.
     */
    #[Transition(
        from: self::PLACE_AI_READY,
        to: self::PLACE_COMPLETE,
        info: 'Finish AI pipeline',
        description: 'All aiQueue tasks done; return to complete',
        guard: "subject.aiQueue == [] and not subject.aiLocked",
    )]
    public const TRANSITION_AI_DONE = 'ai_done';
}
