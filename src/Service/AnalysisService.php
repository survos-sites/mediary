<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Runs lightweight analyses on (usually small) variants.
 * Plug your implementations here (ThumbHash, palette, pHash/CLIP, etc.).
 */
final class AnalysisService
{
    /**
     * @return array<string,mixed> e.g. ['thumbhash'=>'..','colors'=>[...],'phash'=>'...']
     */
    public function analyzeFromBytes(string $bytes, ?string $mime = null): array
    {
        $out = [];

        // Example: ThumbHash (placeholder) — replace with your real impl
        // $out['thumbhash'] = Thumbhash::fromBytes($bytes);

        // Colours come from imgproxy Pro's /info (`average`, `dominant_colors`), not from a
        // local extractor — see docs/local-image-analysis.md.

        // Example: pHash
        // $out['phash'] = $this->perceptualHash($bytes);

        return $out;
    }
}
