<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\AssetAiExecutor;
use App\Entity\Asset;
use App\Service\SidecarService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Survos\AiWorkflowBundle\Task\TaskRegistry;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * An AI task name that resolves to no handler must be LOUD.
 *
 * It used to return ok:false in silence — no log, no exception, nothing in the failure transport,
 * which is indistinguishable from "no AI was requested for this asset". A client asking for a
 * task mediary does not have would simply never get an answer and never learn why.
 *
 * (Found via a client requesting task names that had never existed here. What that client was
 * modelling is its own business; mediary only needs to say clearly that it cannot do the thing.)
 */
#[CoversClass(AssetAiExecutor::class)]
final class AssetAiExecutorUnknownTaskTest extends TestCase
{
    #[Test]
    public function unknownTaskIsLoggedAsAnErrorNamingTheKnownTasks(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level:mixed, message:string, context:array<string,mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        // TaskRegistry is final, so build a real one over an empty service provider: the point
        // of the test is a name that is IN the map but has no handler behind it, which is exactly
        // what a renamed or removed task looks like in production.
        $tasks = new class implements ServiceProviderInterface {
            public function get(string $id): mixed { throw new \RuntimeException('not registered: ' . $id); }
            public function has(string $id): bool { return false; }
            public function getProvidedServices(): array { return []; }
        };
        $registry = new TaskRegistry($tasks, ['observe' => 'x', 'ocr_mistral' => 'y']);

        // Real SidecarService with no storage configured: run() returns before it is ever
        // consulted, and it is final so it cannot be doubled anyway.
        $executor = new AssetAiExecutor($registry, new SidecarService(), logger: $logger);

        $asset = new Asset();
        $asset->id = str_repeat('a', 16);

        $result = $executor->run($asset, 'no_such_task');

        self::assertFalse($result['ok']);
        self::assertSame('task handler not found', $result['reason']);

        $errors = array_values(array_filter($logger->records, static fn (array $r): bool => $r['level'] === 'error'));
        self::assertCount(1, $errors, 'an unknown task must produce exactly one error-level log');
        self::assertStringContainsString('no handler registered', $errors[0]['message']);
        self::assertSame('no_such_task', $errors[0]['context']['task']);
        // The known-task list is the point: the caller almost always has a stale or misspelled
        // name, and seeing what IS registered is the shortest path to the fix.
        self::assertSame('observe, ocr_mistral', $errors[0]['context']['known']);
    }
}
