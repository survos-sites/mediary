<?php

declare(strict_types=1);

namespace App\Ai;

use Survos\ImgproxyBundle\Dto\ImgproxyInfo;

/**
 * Derived layout facts from imgproxy's detect_objects face boxes.
 *
 * The boxes are already fetched and stored (context['info']['objects']) — /info always runs
 * before Observe — but only count() was being read off them. Everything here is arithmetic on
 * boxes we already paid for: no model, no extra call, deterministic, and stable across model
 * versions in a way an LLM's "how many people are in this photo?" is not.
 *
 * The point is to hand Observe the facts it is worst at (counting, measuring arrangement) so its
 * attention goes to what it is uniquely good at — what the focus is, what the action is. It is
 * ALSO the cheapest available signal for demoting the posed group shot, which is the bulk of what
 * makes a public gallery dull.
 *
 * Coordinates from imgproxy are normalised 0..1 against the frame, so every measure here is
 * resolution-independent and comparable across a whole archive.
 *
 * Deliberately NOT face recognition: no identity, no embeddings, no matching a person across
 * photographs. Counting faces and measuring their arrangement answers "how many and how arranged",
 * never "who". For archives of real people — many with living descendants — that boundary is a
 * design decision, not an oversight, and it should stay that way.
 */
final readonly class FaceGeometry
{
    public const LAYOUT_NONE = 'none';
    public const LAYOUT_PORTRAIT = 'portrait';
    public const LAYOUT_PAIR = 'pair';
    public const LAYOUT_SMALL_GROUP = 'small_group';
    public const LAYOUT_CLASS_OR_TEAM = 'class_or_team';
    public const LAYOUT_CROWD = 'crowd';

    /** Boxes below this confidence are noise; matches imgproxy's own detection threshold. */
    private const MIN_CONFIDENCE = 0.4;

    /** A "class or team" arrangement needs at least this many faces. */
    private const LINEUP_MIN_FACES = 8;

    /** …arranged in at most this many rows. Beyond that it is a crowd, not a lineup. */
    private const LINEUP_MAX_ROWS = 4;

    /**
     * …with face heights within a row varying by less than this (coefficient of variation).
     * This is the discriminator that matters: in a posed lineup everyone stands the same distance
     * from the camera, so face heights within a row are near-identical. In a crowd, face scale
     * varies continuously with depth. Nothing else separates those two cases as cheaply.
     */
    private const LINEUP_MAX_ROW_SCALE_CV = 0.25;

    /** …and spaced evenly (1.0 = perfectly regular gaps). */
    private const LINEUP_MIN_SPACING_UNIFORMITY = 0.55;

    /**
     * @param int          $count             faces above MIN_CONFIDENCE
     * @param string       $layout            one of the LAYOUT_* constants
     * @param float|null   $largestFaceArea   biggest face as a fraction of the frame (portrait vs environmental)
     * @param float|null   $medianFaceArea    median face as a fraction of the frame
     * @param int          $rows              horizontal bands the faces fall into
     * @param float|null   $rowScaleVariation mean within-row coefficient of variation of face height
     * @param float|null   $spacingUniformity 0..1 regularity of horizontal gaps within rows
     */
    public function __construct(
        public int $count,
        public string $layout,
        public ?float $largestFaceArea = null,
        public ?float $medianFaceArea = null,
        public int $rows = 0,
        public ?float $rowScaleVariation = null,
        public ?float $spacingUniformity = null,
    ) {
    }

    /**
     * Preferred entry point: the bundle's DTO already normalises the payload key
     * (objects / detected_objects / do), so callers holding an ImgproxyInfo should use this
     * rather than reaching into the raw array and guessing which spelling this response used.
     */
    public static function fromInfo(ImgproxyInfo $info): self
    {
        return self::fromObjects($info->objects);
    }

    /**
     * @param array<int, mixed>|null $objects raw imgproxy detect_objects entries
     */
    public static function fromObjects(?array $objects): self
    {
        $faces = self::faces($objects);
        if ($faces === []) {
            return new self(0, self::LAYOUT_NONE);
        }

        $areas = array_map(static fn (array $f): float => $f['width'] * $f['height'], $faces);
        $rows = self::rows($faces);

        $scaleCv = self::meanWithinRowScaleCv($rows);
        $spacing = self::meanSpacingUniformity($rows);

        return new self(
            count: count($faces),
            layout: self::classify(count($faces), count($rows), $scaleCv, $spacing),
            largestFaceArea: round(max($areas), 5),
            medianFaceArea: round(self::median($areas), 5),
            rows: count($rows),
            rowScaleVariation: $scaleCv === null ? null : round($scaleCv, 4),
            spacingUniformity: $spacing === null ? null : round($spacing, 4),
        );
    }

    /**
     * A sentence for the Observe prompt. Stating these as given facts stops the model
     * re-deriving them badly, and stops it hedging ("appears to be several people").
     */
    public function promptSummary(): string
    {
        if ($this->count === 0) {
            return 'Face detection found no faces in this image.';
        }

        $parts = [sprintf(
            'Face detection (objective, already measured) found %d face%s',
            $this->count,
            $this->count === 1 ? '' : 's',
        )];

        if ($this->rows > 1) {
            $parts[] = sprintf('arranged in %d horizontal rows', $this->rows);
        }

        if ($this->largestFaceArea !== null) {
            $parts[] = sprintf('the largest covering %.1f%% of the frame', $this->largestFaceArea * 100);
        }

        $sentence = implode(', ', $parts) . '.';

        if ($this->layout === self::LAYOUT_CLASS_OR_TEAM) {
            $sentence .= ' The even spacing and near-identical face sizes within each row are'
                . ' characteristic of a posed group, class or team photograph.';
        }

        return $sentence . ' Treat these counts as given; do not re-count. Describe instead what the'
            . ' focus of the image is and what is happening in it.';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'count' => $this->count,
            'layout' => $this->layout,
            'largestFaceArea' => $this->largestFaceArea,
            'medianFaceArea' => $this->medianFaceArea,
            'rows' => $this->rows,
            'rowScaleVariation' => $this->rowScaleVariation,
            'spacingUniformity' => $this->spacingUniformity,
        ];
    }

    /**
     * Normalise raw entries to {cx, cy, width, height}, keeping only confident face boxes.
     *
     * @param array<int, mixed>|null $objects
     * @return list<array{cx: float, cy: float, width: float, height: float}>
     */
    private static function faces(?array $objects): array
    {
        if (!is_array($objects)) {
            return [];
        }

        $faces = [];
        foreach ($objects as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            // This deployment's detection model is face-only, but the payload still carries
            // class_name — honour it so a future multi-class model does not silently pollute
            // the geometry with chairs and dogs.
            if (($entry['class_name'] ?? 'face') !== 'face') {
                continue;
            }
            if ((float) ($entry['confidence'] ?? 1.0) < self::MIN_CONFIDENCE) {
                continue;
            }

            $w = (float) ($entry['width'] ?? 0);
            $h = (float) ($entry['height'] ?? 0);
            if ($w <= 0.0 || $h <= 0.0) {
                continue;
            }

            $faces[] = [
                'cx' => (float) ($entry['left'] ?? 0) + $w / 2,
                'cy' => (float) ($entry['top'] ?? 0) + $h / 2,
                'width' => $w,
                'height' => $h,
            ];
        }

        return $faces;
    }

    /**
     * Group faces into horizontal bands. Two faces belong to the same row when their centres are
     * within one median face-height of each other vertically — a scale-relative threshold, so it
     * works the same for a tight head-and-shoulders pair and a distant assembly.
     *
     * @param list<array{cx: float, cy: float, width: float, height: float}> $faces
     * @return list<list<array{cx: float, cy: float, width: float, height: float}>>
     */
    private static function rows(array $faces): array
    {
        usort($faces, static fn (array $a, array $b): int => $a['cy'] <=> $b['cy']);

        $tolerance = self::median(array_map(static fn (array $f): float => $f['height'], $faces));
        if ($tolerance <= 0.0) {
            return [$faces];
        }

        $rows = [];
        $current = [];
        $anchor = null;

        foreach ($faces as $face) {
            if ($anchor === null || abs($face['cy'] - $anchor) <= $tolerance) {
                $anchor ??= $face['cy'];
                $current[] = $face;
                continue;
            }
            $rows[] = $current;
            $current = [$face];
            $anchor = $face['cy'];
        }

        if ($current !== []) {
            $rows[] = $current;
        }

        return $rows;
    }

    /**
     * Mean coefficient of variation of face height within each row of 2+, or null when no row is
     * big enough to say anything. Low = everyone the same distance from the lens (posed lineup).
     *
     * @param list<list<array{cx: float, cy: float, width: float, height: float}>> $rows
     */
    private static function meanWithinRowScaleCv(array $rows): ?float
    {
        $cvs = [];
        foreach ($rows as $row) {
            if (count($row) < 2) {
                continue;
            }
            $heights = array_map(static fn (array $f): float => $f['height'], $row);
            $mean = array_sum($heights) / count($heights);
            if ($mean <= 0.0) {
                continue;
            }
            $cvs[] = self::stddev($heights) / $mean;
        }

        return $cvs === [] ? null : array_sum($cvs) / count($cvs);
    }

    /**
     * Mean regularity of horizontal gaps within each row of 3+, mapped to 0..1 where 1 is
     * perfectly even. Needs three faces to have two gaps to compare, hence the floor.
     *
     * @param list<list<array{cx: float, cy: float, width: float, height: float}>> $rows
     */
    private static function meanSpacingUniformity(array $rows): ?float
    {
        $scores = [];
        foreach ($rows as $row) {
            if (count($row) < 3) {
                continue;
            }
            usort($row, static fn (array $a, array $b): int => $a['cx'] <=> $b['cx']);

            $gaps = [];
            for ($i = 1, $n = count($row); $i < $n; $i++) {
                $gaps[] = $row[$i]['cx'] - $row[$i - 1]['cx'];
            }

            $mean = array_sum($gaps) / count($gaps);
            if ($mean <= 0.0) {
                continue;
            }
            // 1/(1+CV) keeps this bounded in (0,1] without clamping — even gaps → CV 0 → 1.0.
            $scores[] = 1 / (1 + self::stddev($gaps) / $mean);
        }

        return $scores === [] ? null : array_sum($scores) / count($scores);
    }

    private static function classify(int $count, int $rows, ?float $scaleCv, ?float $spacing): string
    {
        return match (true) {
            $count === 0 => self::LAYOUT_NONE,
            $count === 1 => self::LAYOUT_PORTRAIT,
            $count === 2 => self::LAYOUT_PAIR,
            $count < self::LINEUP_MIN_FACES => self::LAYOUT_SMALL_GROUP,
            $rows <= self::LINEUP_MAX_ROWS
                && $scaleCv !== null && $scaleCv <= self::LINEUP_MAX_ROW_SCALE_CV
                && $spacing !== null && $spacing >= self::LINEUP_MIN_SPACING_UNIFORMITY
                => self::LAYOUT_CLASS_OR_TEAM,
            default => self::LAYOUT_CROWD,
        };
    }

    /** @param list<float> $values */
    private static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $mid = (int) floor((count($values) - 1) / 2);

        return count($values) % 2 === 1
            ? $values[$mid]
            : ($values[$mid] + $values[$mid + 1]) / 2;
    }

    /** @param list<float> $values */
    private static function stddev(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;
        $sum = 0.0;
        foreach ($values as $value) {
            $sum += ($value - $mean) ** 2;
        }

        return sqrt($sum / $n);
    }
}
