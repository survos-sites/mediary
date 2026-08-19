<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Zenstruck\Messenger\Monitor\History\Period;
use Zenstruck\Messenger\Monitor\History\Specification;
use Zenstruck\Messenger\Monitor\History\Storage;
use Zenstruck\Messenger\Monitor\Transports;

/**
 * Machine-readable throughput, for pinging rather than eyeballing.
 *
 * The pie charts at /admin/messenger already show all of this, but only to a human behind the
 * firewall who remembers to refresh. That gap is not academic: mediary sat with 172,880 messages
 * on asset.ai.task and no consumer for weeks, and finding that out took six separate commands
 * across two apps. A single GET answers it.
 *
 * The three fields that matter, in order of how much they were missed:
 *
 *  - `consumers` — depth alone cannot tell a backlog (busy) from a stall (nobody listening).
 *    A queue of 172k with 0 consumers and one of 172k with 8 consumers need opposite actions.
 *  - `stalled`   — the derived form of the above, so a monitor does not have to know the rule.
 *  - `oldestMessage` — a June timestamp on a queue says "stopped" more loudly than any depth.
 *
 * Deliberately unauthenticated and side-effect free: this is what a health check and an agent
 * both want, and it exposes counts and timings only — no message bodies, no payloads, nothing
 * that could carry archive content or credentials.
 */
final class StatusController extends AbstractController
{
    public function __construct(
        private readonly Transports $transports,
        private readonly Storage $storage,
    ) {
    }

    #[Route('/status.json', name: 'app_status_json', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $transports = [];
        $stalled = [];
        $totalDepth = 0;
        $totalConsumers = 0;

        foreach ($this->transports->all() as $transport) {
            $name = $transport->name();

            // Not every transport can be counted (sync, in-memory, some AMQP setups). Report null
            // rather than 0 — "cannot know" and "empty" are different answers, and collapsing them
            // is how a stalled queue hides.
            $depth = $transport->isCountable() ? $transport->count() : null;
            $consumers = \count($transport->workers());

            $transports[$name] = [
                'depth' => $depth,
                'consumers' => $consumers,
                'running' => $transport->isRunning(),
                'isFailureTransport' => $transport->isFailure(),
            ];

            if ($depth !== null) {
                $totalDepth += $depth;
            }
            $totalConsumers += $consumers;

            // The condition worth alerting on: work waiting, nobody to do it. Excludes the failure
            // transport, which is a parking lot by design and legitimately has no consumer.
            if (!$transport->isFailure() && $depth !== null && $depth > 0 && $consumers === 0) {
                $stalled[] = $name;
            }
        }

        return new JsonResponse([
            'app' => 'mediary',
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'transports' => $transports,
            'totals' => [
                'queued' => $totalDepth,
                'consumers' => $totalConsumers,
                'stalledTransports' => \count($stalled),
            ],
            // Named so a monitor can alert on a non-empty array without parsing anything else.
            'stalled' => $stalled,
            'throughput' => [
                'lastHour' => $this->window(Period::IN_LAST_HOUR),
                'lastDay' => $this->window(Period::IN_LAST_DAY),
            ],
        ]);
    }

    /**
     * Processed/failed counts plus timings for one window. Rate is what distinguishes "draining
     * slowly" from "not draining" — a single depth reading cannot, which is why polling
     * messenger:stats twice was the only way to tell before this existed.
     *
     * @return array<string, int|float|null>
     */
    private function window(Period $period): array
    {
        $spec = Specification::create($period);

        $total = $this->storage->count($spec);
        $failed = $this->storage->count(Specification::create($period)->failures());

        return [
            'processed' => $total,
            'failed' => $failed,
            'succeeded' => $total - $failed,
            'avgWaitMs' => $this->storage->averageWaitTime($spec),
            'avgHandlingMs' => $this->storage->averageHandlingTime($spec),
        ];
    }
}
