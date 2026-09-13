<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\MediaRecord;
use Survos\StateBundle\Attribute\Place;
use Survos\StateBundle\Attribute\Transition;
use Survos\StateBundle\Attribute\Workflow;

#[Workflow(name: self::WORKFLOW_NAME, supports: [MediaRecord::class])]
final class MediaRecordFlow
{
    public const WORKFLOW_NAME = 'media_record';

    #[Place(initial: true, info: 'Record created and awaiting linked assets', next: [self::TRANSITION_GROUP_ASSETS])]
    public const PLACE_NEW = 'new';

    // Splitting is still a scaffold, so registration must not enqueue a split
    // for every record. Asset processing runs through its own workflow.
    #[Place(info: 'Assets associated to the record', next: [self::TRANSITION_QUEUE_AI])]
    public const PLACE_GROUPED = 'grouped';

    #[Place(info: 'Split requested for compound payloads (e.g. PDF)', next: [self::TRANSITION_SPLIT_ASSETS])]
    public const PLACE_SPLIT_READY = 'split_ready';

    #[Place(info: 'Child assets generated from compound source', next: [self::TRANSITION_QUEUE_AI])]
    public const PLACE_SPLIT_DONE = 'split_done';

    #[Place(info: 'Record-level AI task queue active', next: [self::TRANSITION_AI_TASK])]
    public const PLACE_AI_READY = 'ai_ready';

    #[Place(info: 'Record processing complete')]
    public const PLACE_COMPLETE = 'complete';

    #[Place(info: 'Terminal or retriable failure')]
    public const PLACE_FAILED = 'failed';

    #[Transition(from: [self::PLACE_NEW, self::PLACE_FAILED], to: self::PLACE_GROUPED, info: 'Link assets')]
    public const TRANSITION_GROUP_ASSETS = 'group_assets';

    #[Transition(from: [self::PLACE_GROUPED], to: self::PLACE_SPLIT_READY, info: 'Queue split', async: true, next: [self::TRANSITION_SPLIT_ASSETS])]
    public const TRANSITION_QUEUE_SPLIT = 'queue_split';

    #[Transition(from: [self::PLACE_SPLIT_READY], to: self::PLACE_SPLIT_DONE, info: 'Split compound asset', async: true)]
    public const TRANSITION_SPLIT_ASSETS = 'split_assets';

    #[Transition(
        from: [self::PLACE_GROUPED, self::PLACE_SPLIT_DONE, self::PLACE_COMPLETE],
        to: self::PLACE_AI_READY,
        info: 'Queue AI tasks',
        guard: 'subject.aiQueue != [] and not subject.aiLocked'
    )]
    public const TRANSITION_QUEUE_AI = 'queue_ai';

    #[Transition(
        from: [self::PLACE_AI_READY],
        to: self::PLACE_AI_READY,
        info: 'Run next AI task',
        guard: 'subject.aiQueue != [] and not subject.aiLocked',
        async: true
    )]
    public const TRANSITION_AI_TASK = 'ai_task';

    #[Transition(
        from: [self::PLACE_AI_READY],
        to: self::PLACE_COMPLETE,
        info: 'AI complete',
        guard: 'subject.aiQueue == [] and not subject.aiLocked'
    )]
    public const TRANSITION_AI_DONE = 'ai_done';

    #[Transition(from: [self::PLACE_GROUPED, self::PLACE_SPLIT_READY, self::PLACE_SPLIT_DONE, self::PLACE_AI_READY], to: self::PLACE_FAILED, info: 'Mark failed')]
    public const TRANSITION_FAIL = 'fail';
}
