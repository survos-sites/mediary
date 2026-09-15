<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Entity\Asset;
use App\Service\PdfToolsClient;
use App\Workflow\AssetWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;

final class PdfArchiveTest extends TestCase
{
    public function testOwnedPdfUsesSourceWithoutArchiveUploadOrImageProbe(): void
    {
        $file = ['id' => 'file', 'sha256' => str_repeat('a', 64), 'pages' => 7, 'bytes' => 12345];
        $client = new PdfToolsClient(new MockHttpClient(new MockResponse(json_encode($file))), 'http://localhost:5001');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $reflection = new \ReflectionClass(AssetWorkflow::class);
        $flow = $reflection->newInstanceWithoutConstructor();
        foreach (['ownedPdfHosts' => 'nabolom.example.org', 'pdfTools' => $client, 'em' => $em] as $key => $value) {
            $reflection->getProperty($key)->setValue($flow, $value);
        }
        // No archive store, image probe, or thumbnail service initialized: touching one fails.
        $asset = new Asset();
        $asset->originalUrl = 'https://nabolom.example.org/folder.pdf';
        $flow->onArchive(new TransitionEvent($asset, new Marking(), new Transition('archive', 'new', 'archived')));
        self::assertSame($asset->originalUrl, $asset->archiveUrl);
        self::assertNull($asset->storageKey);
        self::assertSame('source', $asset->storageBackend);
        self::assertSame('application/pdf', $asset->mime);
        self::assertSame(7, $asset->context['pdf']['pages']);
        self::assertSame(12345, $asset->size);
    }
}
