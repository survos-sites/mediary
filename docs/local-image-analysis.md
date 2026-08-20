# Local image analysis: what we compute, what imgProxy Pro computes

mediary has historically had **two** image-analysis paths: local PHP running over a downloaded
file, and imgProxy **Pro**'s `/info` endpoint. This page records what each one produces, which
tools have been retired, and — for the retired ones — enough of a recipe to rebuild them in an
afternoon without Pro.

That last part matters for **depot**, which has the source image on disk locally and no imgProxy
Pro licence.

All measurements below verified against `imgproxy.survos.com` on **2026-08-20**:

```bash
bin/console imgproxy:info --opt=average --opt=dominant_colors --opt=palette:8 \
    --opt=perceptual_hash --opt=thumb_hash --opt=blurhash:4:3 --json
```

---

## Current state at a glance

`AssetWorkflow::onInfo()` already requests `thumb_hash`, `blurhash:4:3`, `perceptual_hash`,
`average` and `dominant_colors` on every asset, and stores the whole response under
`Asset::$context['info']`. **`applyInfoMetadata()` reads none of the hash or colour fields.**
Meanwhile `onLocalAnalyze()` still recomputes thumbhash and pHash locally into separate
top-level context keys.

So for two of the three tools we currently pay for both and use the local one.

| Tool | Local implementation | imgProxy Pro field | Status |
|---|---|---|---|
| Colour palette | ~~`league/color-extractor` + `ColorAnalysisService`~~ | `average`, `dominant_colors`, `palette:N` | **Removed 2026-08-20.** Recipe below. |
| pHash | `jenssegers/imagehash` (`PerceptualHash`) → `context['phash']` | `perceptual_hash` → `context['info']['perceptual_hash']` | **Both running.** Not interchangeable — see below. |
| ThumbHash | `survos/thumb-hash-bundle` → `context['thumbhash']` | `thumb_hash`, `blurhash` → `context['info']` | **Both running.** |

Coverage in the local `mediary-temp` DB (3,632 assets): local `phash` 9, local `thumbhash` 9,
imgProxy `perceptual_hash` 63, `thumb_hash` 63, `blurhash` 63, `dominant_colors` 63. Only 63
assets have an `info` payload at all — the local path predates the imgProxy Pro one and most
rows have neither.

---

## Colour — removed

### Parity

| Old local output | imgProxy Pro equivalent | Status |
|---|---|---|
| `color_analysis.avg` (count-weighted mean) | `average` → `{R,G,B,A}` | ✅ requested today |
| `color_analysis.dominant` (highest-count colour) | `dominant_colors` → six named swatches: `vibrant`, `muted`, `dark_vibrant`, `dark_muted`, `light_vibrant`, `light_muted` | ✅ requested today — but **semantic swatches, not a frequency ranking.** Not the same quantity. |
| `colors` / `color_analysis.palette` (`extract(5)`) | `palette:N` (N = 2–256) → list of `{R,G,B,A}` | ⚠️ **works, but mediary does not request it.** This is the true `ColorExtractor::extract()` equivalent. |
| `color_analysis.dist[].count` / `.ratio` | — | ❌ **no equivalent.** imgProxy returns colours, not a weighted histogram. "This image is 40 % blue" is not recoverable from `/info`. |
| `color_analysis.hueBuckets` | — | ❌ not returned, but derivable from `palette` via RGB→HSL — *unweighted* bucket membership, where the old version weighted by pixel count. |

Everything anything actually consumed carries over. The frequency-weighted distribution does not,
and nothing consumed it. If a future feature needs true pixel ratios, `/info` cannot supply them
and the recipe below is the fallback *even with Pro available*.

### What the old step did, in order

`AssetPreviewService::maybeComputePaletteAndPhash($asset, $preset, $cachedPath)`, called from
`AssetWorkflow::onLocalAnalyze()` (the `analyze` transition, `informed|triaged → analyzed`),
guarded by `empty($asset->context['colors']) && $asset->archiveUrl`. Source path was
`localImagePath($asset, preferSmall: true) ?? $asset->archiveUrl`.

1. **Get a local file.** If the path was `http(s)://`, `file_get_contents()` into
   `tempnam(sys_get_temp_dir(), 'asset_preview_')`. Otherwise use as-is.
2. **Downsample for speed.** `createAnalysisSizedImage($path, 512)`: Imagick
   `thumbnailImage(512, 512, true)` (bestfit — 512 is the max *side*), written to
   `tempnam(sys_get_temp_dir(), 'asset_palette_')`. **Skipped entirely** when the image was
   already ≤512 on both sides.
3. **Build the histogram.** `League\ColorExtractor\Palette::fromFilename($path, -1, 500)`.
4. **Top-N palette.** `(new ColorExtractor($palette))->extract(5)` → 5 ints (`0xRRGGBB`) →
   `Asset::$context['colors']`.
5. **Richer analysis.** `ColorAnalysisService::analyze($path, top: 5, hueBuckets: 36)` →
   `Asset::$context['color_analysis']`.
6. **Clean up.** `unlink()` both temps. Whole block wrapped in `try { … } catch (\Throwable) {}` —
   failure never failed the transition.

### Tuning that mattered

| Setting | Value | Why |
|---|---|---|
| `Palette::fromFilename` arg 2 (`$backgroundColor`) | `-1` | No background substitution; transparency left alone. |
| `Palette::fromFilename` arg 3 (`$maxColors`) | `500` | Added in `31dd300` — full-res images produced hundreds-of-thousands-entry histograms that blew up the JSON in `Asset.context`. |
| Downsample max side | `512` | Speed only. Skipped when already smaller. |
| `extract()` count | `5` | Size of `context['colors']`. |
| `analyze(top:)` | `5` | Caps both `palette` and `dist`. |
| `analyze(hueBuckets:)` | `36` | 36 bins of 10°, for coarse hue faceting. |

### `color_analysis` output shape

`ColorAnalysisService` walked the same `Palette` histogram itself (it did not reuse the
`ColorExtractor` result) with a hand-rolled RGB→HSL — no library beyond `Palette`:

```php
[
  'dominant'   => 0xRRGGBB,          // highest-count colour, or null
  'palette'    => [0xRRGGBB, ...],   // top 5, from ColorExtractor::extract()
  'dist'       => [                  // top 5 rows, sorted by count desc
    ['rgb' => int, 'hex' => '#RRGGBB', 'count' => int, 'ratio' => float,
     'h' => int, 's' => int, 'l' => int, 'hueBucket' => int],
  ],
  'avg'        => ['rgb' => int, 'hex' => '#RRGGBB', 'h' => int, 's' => int, 'l' => int],
  'hueBuckets' => [int, ...],        // indices present (0..35)
  '_total'     => int,               // total pixel count, debugging only
]
```

`ratio` is `count / _total`. `avg` is the count-weighted mean over the whole histogram, not just
the top 5.

### Library

`league/color-extractor` **0.4.0** — https://github.com/thephpleague/color-extractor

Pure PHP, needs only GD, zero config. `Palette` is `IteratorAggregate<int $rgb, int $count>`, so
the raw histogram is available without `ColorExtractor` at all — that is how `ColorAnalysisService`
worked.

### Downstream impact of the removal

- `context['colors']` is in `AssetNotifier::CONTEXT_KEYS`, the whitelist sent to clients on
  `asset.analyzed`. New assets no longer get it. Clients wanting colour should read
  `context['info']['average']` / `['dominant_colors']` — same payload, `info` is whitelisted too.
- `context['color_analysis']` was never sent over the webhook. Its only consumer was a pair of
  EasyAdmin field templates bound to a `colorAnalysis` property `Asset` does not have, so that
  admin field had been throwing. Templates and field removed with the rest.

---

## pHash — still local, and the two are NOT interchangeable

Both are 64-bit DCT perceptual hashes, 16 hex chars. They are **different implementations**, and
that is not a detail you can wave away. Hashing the identical image with each:

```
jenssegers  : aa6bc92d63d63c73     (local, jenssegers/imagehash PerceptualHash)
imgproxy    : a46ddb07f2124d69     (imgproxy Pro perceptual_hash)
hamming     : 23 / 64
```

Near-duplicate detection typically thresholds at **≤10**. At 23 bits apart, the two tools describe
the same image as *unrelated*. Mixing them in one column, or switching sources without a
rehash, silently destroys any comparison that column exists to support.

Today nothing in mediary actually compares pHashes — `context['phash']` is written and shipped
over the webhook, never read back. So the incompatibility is latent, not yet a bug. But there are
already 9 assets with a Jenssegers hash and 63 with an imgProxy hash under a different key, and
the moment anyone writes a dedup query across both sets they will get nonsense.

**Decide before unifying.** Either back-fill everything with one implementation, or keep the two
under distinct key names forever and document which one consumers must use. Do not quietly
repoint `context['phash']` at `info.perceptual_hash`.

If local pHash does go away, `jenssegers/imagehash`, `createAnalysisSizedImage()`, and the entire
download-to-temp path in `AssetPreviewService::maybeComputePhash()` go with it — that method
exists for nothing else now that colour is gone.

---

## ThumbHash / blurhash — still local, lower stakes

Same duplication (local `context['thumbhash']` vs imgProxy `info.thumb_hash` / `info.blurhash`,
9 rows vs 63), but the risk profile is different: a thumbhash is a **rendering** format — a tiny
placeholder image — not a comparison key. Two implementations disagreeing produces a slightly
different blur, not a broken query. Switching sources is a visual change, not a data migration.

Note `blurhash` needs **two** components (`bh:x:y`, each 1–9); a valueless `blurhash` token
makes `/info` return 404.

---

## If depot needs colour extraction

depot has the source image on disk and no imgProxy Pro. Two roads; the right one depends on
depot's stack at the time:

- **`league/color-extractor` (PHP)** — no new runtime, drops straight into a Symfony service, and
  the recipe above transcribes directly. Against it: 0.4.0 is old and quiet, and the output is a
  plain palette with no perceptual clustering.
- **Python (`colorthief`, or Pillow + k-means / `scikit-learn`)** — depot already runs Python, so
  it is less a new runtime than a new process boundary. Better algorithms, at the cost of a
  subprocess or HTTP hop per image and a second place to keep the tuning constants.

**Not decided, and deliberately not built.** Compare against depot's real stack when the need is
real.

---

## Where the removed code lived

Last state before removal is `602361e` (2026-08-19):

```bash
git show 602361e:src/Service/AssetPreviewService.php
git show 602361e:src/Service/ColorAnalysisService.php
git show 602361e:templates/easy_admin/field/colors_detail.html.twig
```

`ColorAnalysisService` was introduced in `8a86a50` ("initial version (was sais)") and last touched
in `31dd300` (2026-03-02, the 500-colour cap).
