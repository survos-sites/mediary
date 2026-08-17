<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Asset;
use App\Repository\AssetRepository;
use App\Service\AssetNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-delivers asset.analyzed for assets whose state a client never received —
 * because the callback_url was unreachable at the time, or (mediary#7) because
 * no client had ever sent one.
 *
 * Selection is "has an archiveUrl", NOT "marking = analyzed". Assets do not
 * stop at PLACE_ANALYZED; they go on to PLACE_COMPLETE, so filtering on the
 * live event's place would skip everything that actually finished. What makes
 * an asset worth replaying is that mediary holds S3 state the client doesn't.
 *
 * Usage:
 *   php bin/console media:replay-webhooks --dry-run
 *   php bin/console media:replay-webhooks --client=harvest --limit=100
 *   php bin/console media:replay-webhooks --id=fd1230ed5a6267c0
 */
#[AsCommand(name: 'media:replay-webhooks', description: 'Re-deliver asset.analyzed to clients that never got it')]
final class ReplayWebhooksCommand extends Command
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly AssetNotifier $assetNotifier,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be sent without firing')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Replay a single asset by ID')
            ->addOption('client', null, InputOption::VALUE_REQUIRED, 'Only assets registered to this client')
            ->addOption('marking', null, InputOption::VALUE_REQUIRED, 'Only assets in this place (default: any archived asset)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many assets');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $dryRun   = (bool) $input->getOption('dry-run');
        $singleId = $input->getOption('id');
        $client   = $input->getOption('client');
        $marking  = $input->getOption('marking');
        $limit    = $input->getOption('limit');
        $limit    = $limit === null ? null : (int) $limit;

        $fired = $failed = $skipped = 0;

        foreach ($this->assets($singleId, $client, $marking, $limit) as $asset) {
            $callbackUrl = $asset->context['callback_url'] ?? null;
            if (!$callbackUrl) {
                // The overwhelmingly common case for the 2026-08 backfill: these
                // assets were registered before any client sent a callback_url,
                // so there is nowhere to replay TO. Only counted, not printed —
                // at 608k assets the log would be the output.
                $skipped++;
                continue;
            }

            $io->text(sprintf('  %s → %s (image_id=%s)', $asset->id, $callbackUrl, $asset->context['image_id'] ?? '?'));

            if ($dryRun) {
                $skipped++;
                continue;
            }

            // Same payload the live webhook sends — AssetNotifier owns both, so
            // a replay can no longer deliver a different body than the original.
            //
            // This QUEUES rather than delivers, which is what makes a 608k-asset backfill
            // survivable: the command finishes at the speed of the bus, and the worker drains
            // it with per-message retries instead of the command dying two hours in on one
            // subscriber's timeout. `Queued` below is therefore not a euphemism for `Fired`
            // — nothing here knows whether delivery succeeded. Watch the worker for that.
            $this->assetNotifier->notify($asset, (string) $callbackUrl) ? $fired++ : $failed++;
        }

        $io->success(sprintf('Queued: %d  Rejected: %d  Skipped: %d', $fired, $failed, $skipped));

        if ($fired > 0) {
            $io->note('Queued only. Run the webhook worker to deliver: bin/console messenger:consume webhook -v');
        }

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Stream assets rather than findBy()-ing them.
     *
     * The backfill this command exists for is ~608k archived assets; hydrating
     * that as one array is an out-of-memory crash, not a slow run.
     *
     * @return iterable<Asset>
     */
    private function assets(?string $singleId, ?string $client, ?string $marking, ?int $limit): iterable
    {
        if ($singleId) {
            $asset = $this->assetRepository->find($singleId);
            if ($asset) {
                yield $asset;
            }

            return;
        }

        // "There is S3 state to report", not "is sitting in the analyzed place".
        // Assets pass through PLACE_ANALYZED on their way to PLACE_COMPLETE, so
        // the old marking filter matched only the handful caught mid-flight.
        $qb = $this->assetRepository->createQueryBuilder('a')
            ->where('a.archiveUrl IS NOT NULL');

        if ($marking !== null) {
            $qb->andWhere('a.marking = :marking')->setParameter('marking', $marking);
        }

        $query = $qb->getQuery();

        $seen = 0;
        foreach ($query->toIterable() as $asset) {
            // clients is a JSON array and mediary has no DQL function to search
            // one, so this filters in PHP. Fine for a backfill: the query is
            // already streaming and the alternative is a bespoke DQL extension
            // for a maintenance command.
            if ($client !== null && !in_array($client, $asset->clients, true)) {
                continue;
            }

            yield $asset;

            if (++$seen % 200 === 0) {
                $this->em->clear();
            }
            if ($limit !== null && $seen >= $limit) {
                return;
            }
        }
    }
}
