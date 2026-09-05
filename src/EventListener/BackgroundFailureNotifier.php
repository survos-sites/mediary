<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Bridge\Ntfy\NtfyOptions;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * Pushes a background-job failure to ntfy the moment it stops being retried.
 *
 * Why this exists: every AI task in this app runs on a Messenger consumer, so a
 * failure surfaces nowhere a human is looking -- no browser is open (which rules
 * out Mercure, whose updates only reach connected subscribers and are not
 * stored) and the log file is read rarely enough that model/key regressions have
 * survived in it for days.
 *
 * Two rules this class must never break:
 *
 *  1. It fires only on the final attempt (willRetry() === false). A transient
 *     502 that succeeds on retry is not an incident and must not buzz a phone.
 *  2. It can never throw. This runs inside Messenger's failure path; an
 *     exception raised here would replace the real error with an alerting error
 *     and lose the thing we were trying to report. Every failure to notify is
 *     swallowed into the log.
 */
#[AsEventListener(event: WorkerMessageFailedEvent::class)]
final readonly class BackgroundFailureNotifier
{
    public function __construct(
        private ChatterInterface $chatter,
        private LoggerInterface $logger,
        #[Autowire('%env(default::NTFY_DSN)%')] private ?string $ntfyDsn = null,
        #[Autowire('%env(default::APP_BASE_URL)%')] private ?string $baseUrl = null,
        #[Autowire('%kernel.environment%')] private string $env = 'dev',
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        // Messenger will try again; only the last attempt is news.
        if ($event->willRetry()) {
            return;
        }

        if (($this->ntfyDsn ?? '') === '') {
            $this->logger->warning('BackgroundFailureNotifier: NTFY_DSN is not set, failure not pushed', [
                'message' => $event->getEnvelope()->getMessage()::class,
            ]);
            return;
        }

        try {
            $this->chatter->send($this->build($event));
        } catch (\Throwable $notifyError) {
            // Rule 2. The original failure is already being logged by Messenger;
            // this only records that we could not shout about it.
            $this->logger->error('BackgroundFailureNotifier: could not push to ntfy: {err}', [
                'err' => $notifyError->getMessage(),
            ]);
        }
    }

    private function build(WorkerMessageFailedEvent $event): ChatMessage
    {
        $message = $event->getEnvelope()->getMessage();
        $short = (new \ReflectionClass($message))->getShortName();
        $error = $event->getThrowable();

        $lines = [
            \sprintf('%s on %s', $short, $event->getReceiverName()),
            '',
            (new \ReflectionClass($error))->getShortName() . ': ' . $this->firstLine($error->getMessage()),
        ];

        if ($subject = $this->subjectOf($message)) {
            $lines[] = 'subject: ' . $subject;
        }

        // The single most useful field when debugging a model swap or an expired
        // key: what the provider actually said. An HTTP 4xx/5xx from an AI
        // backend carries its explanation in the body, not in the exception
        // message, which only reports the status code.
        if ($body = $this->providerResponse($error)) {
            $lines[] = '';
            $lines[] = 'provider said: ' . $body;
        }

        // No topic argument: NtfyOptions carries only the payload, and the
        // transport takes the topic from the DSN path.
        $options = (new NtfyOptions())
            ->setTitle(\sprintf('[%s] %s failed', $this->env, $short))
            ->setPriority(4) // high: breaks through on the phone, below "urgent"
            ->setTags(['rotating_light']);

        if ($this->baseUrl) {
            $options->setClick($this->baseUrl);
        }

        return new ChatMessage(implode("\n", $lines), $options);
    }

    /** Pull an identifier off the message without knowing any app message class. */
    private function subjectOf(object $message): ?string
    {
        $parts = [];
        foreach ((new \ReflectionClass($message))->getProperties() as $property) {
            if (!$property->isPublic() && !$property->isReadOnly()) {
                continue;
            }
            if (!$property->isInitialized($message)) {
                continue;
            }
            $value = $property->getValue($message);
            if (is_scalar($value) && !is_bool($value)) {
                $parts[] = $property->getName() . '=' . $value;
            }
            if (count($parts) >= 4) {
                break;
            }
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /** Response body of a failed HTTP call, truncated. Null for non-HTTP errors. */
    private function providerResponse(\Throwable $error): ?string
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if (!$e instanceof HttpExceptionInterface) {
                continue;
            }
            try {
                $body = trim($e->getResponse()->getContent(false));
            } catch (\Throwable) {
                return null;
            }

            return $body === '' ? null : mb_substr($body, 0, 500);
        }

        return null;
    }

    private function firstLine(string $text): string
    {
        $line = strtok(trim($text), "\n");

        return mb_substr($line === false ? '' : $line, 0, 300);
    }
}
