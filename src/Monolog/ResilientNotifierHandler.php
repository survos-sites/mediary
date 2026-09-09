<?php

declare(strict_types=1);

namespace App\Monolog;

use Monolog\Handler\HandlerInterface;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;

/**
 * A notification handler that cannot take the process down with it.
 *
 * NotifierHandler does not catch, so anything wrong with the transport -- an
 * unsupported scheme, an unreachable host, a rejected topic -- is thrown from
 * inside logging. Since the handler only runs when something has ALREADY gone
 * wrong, that turns any error into a fatal one: messenger_ocr_scan crash-looped in
 * production on "The ntfy scheme is not supported", and because Dokku will not
 * promote a release whose processes fail pre-flight, a broken push notifier
 * silently blocked every deploy of the whole app.
 *
 * Alerting is a convenience. It must never be load-bearing.
 *
 * The failure is reported to stderr rather than swallowed, because a notifier that
 * quietly stopped working looks exactly like a system with no errors.
 */
final class ResilientNotifierHandler implements HandlerInterface
{
    private ?HandlerInterface $resolved = null;

    /**
     * @param \Closure(): HandlerInterface $innerFactory
     *
     * The inner handler arrives as a factory rather than an instance because the
     * failure being guarded against happens at CONSTRUCTION: NotifierHandler takes
     * the notifier, and building the notifier resolves every configured DSN, which
     * is where "the ntfy scheme is not supported" is actually thrown. Injecting the
     * handler directly would raise it during container instantiation -- outside any
     * try/catch here, which is exactly the bug this class exists to fix.
     */
    public function __construct(
        private readonly \Closure $innerFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @throws \Throwable when the notifier cannot be built */
    private function inner(): HandlerInterface
    {
        return $this->resolved ??= ($this->innerFactory)();
    }

    public function isHandling(LogRecord $record): bool
    {
        try {
            return $this->inner()->isHandling($record);
        } catch (\Throwable $e) {
            $this->reportOnce($e);

            return false;
        }
    }

    public function handle(LogRecord $record): bool
    {
        try {
            return $this->inner()->handle($record);
        } catch (\Throwable $e) {
            $this->reportOnce($e);

            // false = "not handled", so the next handler still gets the record and
            // the original error reaches the logs by its normal route.
            return false;
        }
    }

    public function handleBatch(array $records): void
    {
        try {
            $this->inner()->handleBatch($records);
        } catch (\Throwable $e) {
            $this->reportOnce($e);
        }
    }

    public function close(): void
    {
        try {
            // Never build the notifier just to close it.
            $this->resolved?->close();
        } catch (\Throwable) {
            // Nothing useful to do while shutting down.
        }
    }

    private function reportOnce(\Throwable $e): void
    {
        static $reported = false;

        // Once per process: the transport is misconfigured for the whole run, so
        // repeating it per record would bury the errors it was meant to announce.
        if ($reported) {
            return;
        }

        $reported = true;

        // Channel deliberately not the notifier's own, or this could recurse.
        $this->logger->error('Push notifications are not being delivered: {reason}', [
            'reason' => $e->getMessage(),
            'exception_class' => $e::class,
        ]);
    }
}
