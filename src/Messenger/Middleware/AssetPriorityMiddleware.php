<?php

declare(strict_types=1);

namespace App\Messenger\Middleware;

use App\Entity\Asset;
use Jwage\PhpAmqpLibMessengerBundle\Transport\AmqpStamp;
use App\Service\CollectionPriority;
use Doctrine\ORM\EntityManagerInterface;
use Survos\StateBundle\Message\TransitionMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/** Stamp native broker priority without changing the transition transport. */
final readonly class AssetPriorityMiddleware implements MiddlewareInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        // A TransportNamesStamp is an override, not a requirement: an asset transition routed by
        // framework.messenger.routing carries none, and requiring one meant those dispatches --
        // the ordinary path -- were published at priority 0 while only explicitly-stamped ones
        // were prioritized. Absent a stamp the message is going to its configured transport, which
        // is a priority queue, so stamp it. `sync` is still excluded: there is no broker there, and
        // an AmqpStamp on a synchronously handled message is meaningless.
        $transport = $envelope->last(TransportNamesStamp::class);
        if (!$envelope->last(ReceivedStamp::class)
            && $message instanceof TransitionMessage
            && is_a($message->className, Asset::class, true)
            && ($transport === null || !in_array('sync', $transport->getTransportNames(), true))) {
            $asset = $this->em->find(Asset::class, $message->id);
            $priority = $asset?->context[CollectionPriority::CONTEXT_KEY] ?? null;
            if (in_array($priority, [CollectionPriority::HIGH, CollectionPriority::NORMAL, CollectionPriority::BULK], true)) {
                $value = match ($priority) {
                    CollectionPriority::HIGH => 3,
                    CollectionPriority::NORMAL => 2,
                    CollectionPriority::BULK => 1,
                };
                $stamp = AmqpStamp::createWithAttributes(['priority' => $value], $envelope->last(AmqpStamp::class));
                $envelope = $envelope->withoutAll(AmqpStamp::class)->with($stamp);
            }
        }
        return $stack->next()->handle($envelope, $stack);
    }
}
