<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\BackgroundFailureNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;

final class BackgroundFailureNotifierTest extends TestCase
{
    public function testRetryableFailureIsNotAnnounced(): void
    {
        $chatter = new RecordingChatter();
        $this->notifier($chatter)(self::event(willRetry: true));

        self::assertSame([], $chatter->sent, 'a failure Messenger will retry is not yet news');
    }

    public function testFinalFailureIsAnnounced(): void
    {
        $chatter = new RecordingChatter();
        $this->notifier($chatter)(self::event(willRetry: false));

        self::assertCount(1, $chatter->sent);
        $body = $chatter->sent[0]->getSubject();
        self::assertStringContainsString('FakeMessage', $body);
        self::assertStringContainsString('asset.ai', $body, 'the queue is named so you know which consumer died');
        self::assertStringContainsString('boom', $body);
    }

    public function testMessageIdentifiersAreIncludedSoTheAlertIsActionable(): void
    {
        $chatter = new RecordingChatter();
        $this->notifier($chatter)(self::event(willRetry: false));

        self::assertStringContainsString('assetId=01ABC', $chatter->sent[0]->getSubject());
    }

    public function testNothingIsSentWhenNoDsnIsConfigured(): void
    {
        $chatter = new RecordingChatter();
        $listener = new BackgroundFailureNotifier($chatter, new NullLogger(), ntfyDsn: '');
        $listener(self::event(willRetry: false));

        self::assertSame([], $chatter->sent, 'an unconfigured laptop must not attempt to notify');
    }

    /**
     * The rule that matters most: this runs inside Messenger's failure path, so a
     * broken notifier must not replace the real exception with its own.
     */
    public function testATransportFailureNeverEscapes(): void
    {
        $listener = $this->notifier(new ThrowingChatter());

        $listener(self::event(willRetry: false));

        $this->addToAssertionCount(1); // reaching here without an exception is the assertion
    }

    private function notifier(ChatterInterface $chatter): BackgroundFailureNotifier
    {
        return new BackgroundFailureNotifier($chatter, new NullLogger(), ntfyDsn: 'ntfy://default/ssai-alerts');
    }

    private static function event(bool $willRetry): WorkerMessageFailedEvent
    {
        $event = new WorkerMessageFailedEvent(
            new Envelope(new FakeMessage('01ABC')),
            'asset.ai',
            new \RuntimeException('boom'),
        );
        if ($willRetry) {
            $event->setForRetry();
        }

        return $event;
    }
}

final class FakeMessage
{
    public function __construct(public string $assetId)
    {
    }
}

final class RecordingChatter implements ChatterInterface
{
    /** @var list<MessageInterface> */
    public array $sent = [];

    public function send(MessageInterface $message): ?SentMessage
    {
        $this->sent[] = $message;

        return null;
    }

    public function supports(MessageInterface $message): bool
    {
        return true;
    }

    public function __toString(): string
    {
        return 'recording';
    }
}

final class ThrowingChatter implements ChatterInterface
{
    public function send(MessageInterface $message): ?SentMessage
    {
        throw new \RuntimeException('ntfy is unreachable');
    }

    public function supports(MessageInterface $message): bool
    {
        return true;
    }

    public function __toString(): string
    {
        return 'throwing';
    }
}
