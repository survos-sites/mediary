<?php

declare(strict_types=1);
namespace App\Tests\Messenger;

use App\Entity\Asset;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp;
use App\Messenger\Middleware\AssetPriorityMiddleware;
use App\Service\CollectionPriority;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Survos\StateBundle\Message\TransitionMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class AssetPriorityMiddlewareTest extends TestCase
{
    public function testPriorityFollowsTransitionsButDoesNotResendReceivedMessages(): void
    {
        $asset = new Asset();
        $asset->context = [CollectionPriority::CONTEXT_KEY => 'high'];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($asset);
        $next = $this->createStub(MiddlewareInterface::class);
        $next->method('handle')->willReturnCallback(static fn ($envelope) => $envelope);
        $stack = $this->createStub(StackInterface::class);
        $stack->method('next')->willReturn($next);
        $middleware = new AssetPriorityMiddleware($em);
        foreach (['archive', 'info', 'ai_task'] as $transition) {
            $envelope = new Envelope(new TransitionMessage('test', Asset::class, $transition, 'asset'), [new TransportNamesStamp(['asset.'.$transition])]);
            $sent = $middleware->handle($envelope, $stack);
            self::assertSame(['asset.'.$transition], $sent->last(TransportNamesStamp::class)->getTransportNames());
            self::assertSame(3, $sent->last(AmqpStamp::class)->getAttributes()['priority']);
            $received = $envelope->with(new ReceivedStamp('asset.priority.high'));
            self::assertSame($received, $middleware->handle($received, $stack));
            $sync = $envelope->withoutAll(TransportNamesStamp::class)->with(new TransportNamesStamp(['sync']));
            self::assertSame($sync, $middleware->handle($sync, $stack));
        }
        // The ordinary path: routed by framework.messenger.routing, so no TransportNamesStamp.
        // Requiring one published every such transition at priority 0.
        $routed = new Envelope(new TransitionMessage('test', Asset::class, 'archive', 'asset'));
        self::assertSame(3, $middleware->handle($routed, $stack)->last(AmqpStamp::class)->getAttributes()['priority']);

        $asset->context = [];
        self::assertSame($envelope, $middleware->handle($envelope, $stack));
    }
}
