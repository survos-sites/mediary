<?php

declare(strict_types=1);
namespace App\Tests\Service;

use App\Service\CollectionPriority as Priority;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class CollectionPriorityTest extends TestCase
{
    public function testSizeBandsAndExplicitOverride(): void
    {
        self::assertSame('high', Priority::resolve(['collectionSize' => 8]));
        self::assertSame('high', Priority::resolve(['collectionSize' => 100]));
        self::assertSame('normal', Priority::resolve(['collectionSize' => 101]));
        self::assertSame('normal', Priority::resolve(['collectionSize' => 10000]));
        self::assertSame('bulk', Priority::resolve(['collectionSize' => 10001]));
        self::assertSame('bulk', Priority::resolve(['collectionSize' => 200000]));
        self::assertSame('normal', Priority::resolve(['urls' => ['https://example.org/a.jpg']]));
        self::assertSame('high', Priority::resolve(['collectionSize' => 200000, 'priority' => 'high']));
        self::assertSame('high', Priority::promote('high', 'bulk'));
        self::assertSame('high', Priority::promote('bulk', 'high'));
    }

    public function testInvalidSizeIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);
        Priority::resolve(['collectionSize' => -1]);
    }

    public function testInvalidPriorityIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);
        Priority::resolve(['priority' => 'urgent']);
    }
}
