<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Entity\Asset;
use App\Workflow\AssetWorkflow;
use PHPUnit\Framework\TestCase;

final class BasicInfoTest extends TestCase
{
    public function testBasicRefreshPreservesDetectionAndHashes(): void
    {
        $asset = new Asset();
        $asset->faceCount = 2;
        $asset->objectIdentifiers = ['face'];
        $asset->objectIdentifierConfidences = ['face' => 0.9];
        $asset->context = ['phash' => 'existing', 'face_geometry' => ['count' => 2]];
        $this->apply($asset, ['width' => 800, 'height' => 600, 'size' => 1234]);
        self::assertSame(800, $asset->width);
        self::assertSame(600, $asset->height);
        self::assertSame(1234, $asset->size);
        self::assertSame(2, $asset->faceCount);
        self::assertSame(['face'], $asset->objectIdentifiers);
        self::assertSame(['face' => 0.9], $asset->objectIdentifierConfidences);
        self::assertSame('existing', $asset->context['phash']);
        self::assertSame(['count' => 2], $asset->context['face_geometry']);
    }

    public function testUnmeasuredFacesDifferFromExplicitEmptyDetection(): void
    {
        $asset = new Asset();
        $this->apply($asset, ['width' => 800, 'height' => 600]);
        self::assertNull($asset->faceCount);
        $this->apply($asset, ['objects' => []]);
        self::assertSame(0, $asset->faceCount);
    }

    public function testAnalyzeRetiresLocalHashTasksWithoutChangingStoredHashes(): void
    {
        $reflection = new \ReflectionClass(AssetWorkflow::class);
        $workflow = $reflection->newInstanceWithoutConstructor();
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('flush');
        $reflection->getProperty('em')->setValue($workflow, $em);

        foreach ([[], ['phash' => 'legacy-phash', 'thumbhash' => 'legacy-thumbhash']] as $legacy) {
            $asset = new Asset();
            $asset->mime = 'image/jpeg';
            $asset->archiveUrl = 'https://example.invalid/must-not-download.jpg';
            $asset->context = $legacy + [
                'tasks' => ['phash', 'thumbhash'],
                'info' => ['perceptual_hash' => 'imgproxy-phash'],
            ];
            $expected = $asset->context;
            $workflow->onLocalAnalyze(new \Symfony\Component\Workflow\Event\TransitionEvent(
                $asset, new \Symfony\Component\Workflow\Marking(),
            ));
            self::assertSame($expected, $asset->context);
        }
    }

    private function apply(Asset $asset, array $info): void
    {
        $reflection = new \ReflectionClass(AssetWorkflow::class);
        $reflection->getMethod('applyInfoMetadata')->invoke($reflection->newInstanceWithoutConstructor(), $asset, $info);
    }
}
