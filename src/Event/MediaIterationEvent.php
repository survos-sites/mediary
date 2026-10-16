<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** One selected entity; listeners opt into work through the supplied context. */
#[Exclude]
final class MediaIterationEvent
{
    /** @var array<string, array<string, mixed>> */
    public array $results = [];

    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly object $entity,
        public readonly array $context = [],
    ) {
    }
}
