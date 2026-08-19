<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\FaceGeometry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The load-bearing claim is that a posed lineup and a candid crowd can be told apart from box
 * geometry alone — within-row face height is near-constant in a lineup (everyone the same
 * distance from the lens) and varies with depth in a crowd. These fixtures exist to prove that,
 * not just to exercise the arithmetic.
 */
final class FaceGeometryTest extends TestCase
{
    public function testNoObjectsYieldsNone(): void
    {
        $geometry = FaceGeometry::fromObjects(null);

        self::assertSame(0, $geometry->count);
        self::assertSame(FaceGeometry::LAYOUT_NONE, $geometry->layout);
        self::assertStringContainsString('no faces', $geometry->promptSummary());
    }

    public function testSingleLargeFaceIsAPortrait(): void
    {
        $geometry = FaceGeometry::fromObjects([self::face(0.35, 0.2, 0.3, 0.4)]);

        self::assertSame(1, $geometry->count);
        self::assertSame(FaceGeometry::LAYOUT_PORTRAIT, $geometry->layout);
        // Environmental vs portrait hinges on this: 0.3 * 0.4 = 12% of frame.
        self::assertEqualsWithDelta(0.12, $geometry->largestFaceArea, 0.0001);
    }

    public function testTwoFacesIsAPair(): void
    {
        $geometry = FaceGeometry::fromObjects([
            self::face(0.2, 0.3, 0.15, 0.2),
            self::face(0.6, 0.31, 0.15, 0.2),
        ]);

        self::assertSame(FaceGeometry::LAYOUT_PAIR, $geometry->layout);
        self::assertSame(1, $geometry->rows);
    }

    public function testLowConfidenceBoxesAreIgnored(): void
    {
        $geometry = FaceGeometry::fromObjects([
            self::face(0.2, 0.3, 0.15, 0.2),
            // array_merge, not `+`: the union operator keeps the left-hand value and would
            // silently leave confidence at the helper's 0.85, testing nothing.
            array_merge(self::face(0.6, 0.3, 0.15, 0.2), ['confidence' => 0.1]),
        ]);

        self::assertSame(1, $geometry->count, 'a sub-threshold box must not become a face');
    }

    public function testNonFaceClassesAreIgnored(): void
    {
        $geometry = FaceGeometry::fromObjects([
            self::face(0.2, 0.3, 0.15, 0.2),
            ['left' => 0.5, 'top' => 0.5, 'width' => 0.2, 'height' => 0.2,
             'class_name' => 'chair', 'confidence' => 0.9],
        ]);

        self::assertSame(1, $geometry->count);
    }

    /** Three rows of evenly spaced, equally sized faces — the school photo. */
    public function testClassPhotoIsDetected(): void
    {
        $geometry = FaceGeometry::fromObjects(self::lineup(rows: 3, perRow: 6));

        self::assertSame(18, $geometry->count);
        self::assertSame(3, $geometry->rows);
        self::assertSame(FaceGeometry::LAYOUT_CLASS_OR_TEAM, $geometry->layout);
        self::assertLessThan(0.25, $geometry->rowScaleVariation);
        self::assertGreaterThan(0.55, $geometry->spacingUniformity);
        self::assertStringContainsString('posed group', $geometry->promptSummary());
    }

    /**
     * Same face count, but scale varies with depth and spacing is irregular — a crowd.
     * This is the case a naive count-only rule cannot distinguish from the class photo above.
     */
    public function testCrowdIsNotMistakenForAClassPhoto(): void
    {
        $geometry = FaceGeometry::fromObjects(self::crowd(18));

        self::assertSame(18, $geometry->count);
        self::assertSame(FaceGeometry::LAYOUT_CROWD, $geometry->layout);
        self::assertNotSame(FaceGeometry::LAYOUT_CLASS_OR_TEAM, $geometry->layout);
    }

    public function testSmallGroupStaysASmallGroupEvenWhenNeatlyArranged(): void
    {
        // 5 faces, perfectly regular — still below the lineup floor, so not a class photo.
        $geometry = FaceGeometry::fromObjects(self::lineup(rows: 1, perRow: 5));

        self::assertSame(FaceGeometry::LAYOUT_SMALL_GROUP, $geometry->layout);
    }

    public function testPromptSummaryTellsTheModelNotToRecount(): void
    {
        $summary = FaceGeometry::fromObjects(self::lineup(rows: 2, perRow: 5))->promptSummary();

        self::assertStringContainsString('10 faces', $summary);
        self::assertStringContainsString('2 horizontal rows', $summary);
        self::assertStringContainsString('do not re-count', $summary);
    }

    public function testToArrayRoundTripsTheDerivedFacts(): void
    {
        $array = FaceGeometry::fromObjects(self::lineup(rows: 2, perRow: 5))->toArray();

        self::assertSame(10, $array['count']);
        self::assertSame(FaceGeometry::LAYOUT_CLASS_OR_TEAM, $array['layout']);
        self::assertArrayHasKey('spacingUniformity', $array);
    }

    /**
     * Rows of identical faces at regular intervals.
     *
     * @return list<array<string, mixed>>
     */
    private static function lineup(int $rows, int $perRow): array
    {
        $faces = [];
        for ($r = 0; $r < $rows; $r++) {
            for ($i = 0; $i < $perRow; $i++) {
                $faces[] = self::face(
                    left: 0.05 + $i * (0.9 / $perRow),
                    top: 0.2 + $r * 0.2,
                    width: 0.06,
                    height: 0.08,
                );
            }
        }

        return $faces;
    }

    /**
     * Faces scattered with depth: sizes range over ~3x and horizontal gaps are irregular,
     * which is what a candid crowd actually looks like to a detector.
     *
     * @return list<array<string, mixed>>
     */
    private static function crowd(int $n): array
    {
        $faces = [];
        // Deterministic pseudo-jitter — no randomness, so the test cannot flake.
        for ($i = 0; $i < $n; $i++) {
            $depth = 0.03 + (($i * 7) % 11) * 0.009;   // face height varies ~0.03..0.12
            $faces[] = self::face(
                left: ((($i * 13) % 17) / 17) * 0.9,
                top: 0.15 + ((($i * 5) % 7) / 7) * 0.6,
                width: $depth * 0.8,
                height: $depth,
            );
        }

        return $faces;
    }

    /** @return array<string, mixed> */
    private static function face(float $left, float $top, float $width, float $height): array
    {
        return [
            'left' => $left,
            'top' => $top,
            'width' => $width,
            'height' => $height,
            'class_id' => 0,
            'class_name' => 'face',
            'confidence' => 0.85,
        ];
    }
}
