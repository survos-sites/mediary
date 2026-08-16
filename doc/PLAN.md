# PLAN.md — Media Server Stabilization and Imgproxy Migration Plan

## Context

This repository provides a centralized media service used by multiple Symfony applications (clients) via `survos/media-bundle`. Historically it supported **Image + Thumbnail** (uploaded originals, async resize jobs, locally stored derivatives). The refactor introduces **Media / Asset / Variant** to support non-image media (e.g., WAV → MP3 bitrates, “thumbnail” equivalents for audio).

Key goals:

- Centralize media ingestion and metadata (dimensions, duration, mime, etc.)
- Centralize variant/rendition URL generation and delivery
- Keep async workflow orchestration via `survos/state-bundle` for download/probe/hash and future AI jobs (OCR/classification/embeddings)
- Transition rendering from **LiipImagine** to **imgproxy** (fast, URL-based transforms), making this app primarily an orchestrator/metadata authority

Constraints clarified:

- Polling-only is acceptable for v1 (webhooks later)
- “Root” is metadata (useful for cleanup/archival), but not a hard tenant boundary
- Client-computable IDs are no longer required; server-authoritative IDs are acceptable

---

## Recommended standard location

Use **`PLAN.md`** at the repository root for a living refactor plan.  
Also consider:

- `docs/architecture.md` (longer-term design)
- `docs/api.md` (external contract)
- `docs/workflows.md` (state machine transitions, async routing)

---

## Immediate Stabilization: Stop regressions, preserve “works as-is”

### Phase 0 — Remove hard-stop debug halts (must-fix first)

There are `dd()` statements (and possibly other hard halts) on runtime paths. These must be removed before further refactoring because they break normal HTTP/worker execution.

Action:
- Replace `dd()` with structured logs that include: `assetId`, `transition`, `preset/filter`, `exception class`, and relevant correlation IDs.
- Capture the actual exception/stack trace and reproduce in tests/CLI.

Exit criteria:
- No hard-stop debug calls in runtime request/workflow paths.
- The same paths now report actionable error logs.

### Phase 1 — Lock the external contract (API invariants)

The core instability has been repeated churn in “how clients talk to the server.” Stabilization requires freezing a minimal v1 contract and covering it with functional tests.

**v1 contract (polling-only):**

1) **Dispatch/Register**
- Input: original URL(s) + requested presets (logical names)
- Output: `asset_id`, `state`, minimal metadata, and (optionally) computed variant URLs

2) **Resolve**
- Input: `asset_id[]`
- Output:
    - `asset_id`
    - `state`
    - `source_url`
    - `meta` (dimensions/duration/mime if known)
    - `variants`: `{ preset: { url, meta? } }`

Important:
- Presets must be **logical** (small/medium/waveform/mp3_128), not implementation names (Liip filter names). Internally, these can map to Liip filters now and imgproxy params later.

Exit criteria:
- Functional tests assert stable JSON keys and semantics for both endpoints.
- At least one image fixture and one audio fixture run end-to-end through dispatch → resolve.

### Phase 2 — Identity strategy

Client-computable IDs are optional. Prefer robust, server-owned identity and treat URL hashes as hints.

Recommended:
- `asset_id`: ULID/UUID (server authoritative)
- `source_url_hash`: optional dedupe hint (per metadata scope)
- `content_hash` (sha256): computed after download for true dedupe/storage identity (optional but useful)

Exit criteria:
- API and DB use a single canonical identifier (`asset_id`).
- Dedupe rules are explicit and tested.

### Phase 3 — Workflow correctness and idempotency (StateBundle)

The system relies on workflows and dynamic routing based on transition names (async transitions tagged `async: true`). This is high leverage but fragile without strict conventions.

Actions:
- Define transition names as constants (no string literals).
- Make transitions idempotent:
    - download: no-op if already downloaded/verified
    - hash: no-op if hash already set
    - probe: no-op if metadata already present
- Document which transitions are async (download/probe/hash typically async; URL computation can be sync).
- Define failure semantics:
    - retryable vs non-retryable failures
    - “failed” state handling and safe re-run strategy

Exit criteria:
- An end-to-end workflow run completes deterministically.
- Re-running transitions does not create duplicate rows or corrupt state.

### Phase 4 — Legacy model coexistence and deprecation

Both old and new DB tables/models may be live during refactor. This must be intentional.

Actions:
- Stop dual-write ambiguity; pick one canonical write model.
- If legacy read-compatibility is required, implement it via a compatibility layer that reads from the canonical model.
- Plan and document migration/backfill steps and final removal.

Exit criteria:
- One canonical model used for all new writes.
- Legacy tables are read-only or explicitly deprecated with a removal plan.

---

## Imgproxy Strategy: Originals vs Variants

### Imgproxy role

Imgproxy is best used as:
- **Fast, on-the-fly transformation** for images
- Variant caching is typically handled by a CDN/reverse proxy cache layer
- This app should generate **signed** imgproxy URLs per preset and return them via `resolve`

### Storage strategy

**Originals**
- Store originals in object storage (S3-compatible, e.g., Hetzner Object Storage) as the durable archive.
- Local filesystem can be used as a working cache, not as the long-term source of truth.

**Variants**
- Do not store image variants by default; compute and return signed URLs (on-demand).
- For expensive non-image derivatives (audio transcodes), materialize variants in async workers (e.g., ffmpeg), store in object storage, and serve via CDN/object URL.

### Key layout for “millions of objects” (S3 + local)

Design for direct key access; avoid relying on listing.

Suggested sharded key scheme:

- Originals:
    - `orig/{sha256[0:2]}/{sha256[2:4]}/{sha256}.{ext}`

- Materialized variants (when needed):
    - `variant/{sha256[0:2]}/{sha256[2:4]}/{preset}/{optionsHash}.{ext}`

This prevents huge flat directories locally and avoids “ls meltdown.” For cleanup/tenant-like operations, prefer DB-driven batched deletes over filesystem recursion.

---

## Improvement Phase (after stabilization)

1) **Variant abstraction**
- Introduce internal interfaces:
    - `VariantUrlGenerator` (computes delivery URL)
    - `VariantMaterializer` (optional; precompute/store expensive derivatives)
- Implementations:
    - LiipImagine-backed (current)
    - Imgproxy-backed (future)

2) **Preset registry**
- Define presets in a first-class registry (logical preset → transform definition).
- Map logical presets to Liip filters now; later map them to imgproxy URL params.

3) **Broaden metadata for non-images**
- Audio/video: duration, codec, bitrate, channels, sample rate
- “thumbnail” equivalents: waveform image variant or placeholder strategy

4) **Composable workflows**
- Keep ingestion minimal path: register → download → hash → probe
- Add optional flows later: analyze (OCR/classify/embeddings), transcode

5) **API versioning (v2)**
- Introduce webhooks/queue integration only after v1 polling is stable.
- Provide a “capabilities/presets” endpoint so clients can discover available variants.

---

## Operational checklists

### Stabilization exit checklist

- [ ] No `dd()` / hard-stop debug calls in runtime paths
- [ ] Dispatch + Resolve endpoints covered by functional tests
- [ ] End-to-end processing works for at least one image and one audio fixture
- [ ] Workflow transitions are constant-driven and idempotent
- [ ] Async routing is documented and consumers are configured
- [ ] Canonical model selected; legacy handling is explicit

### Debugging approach (replacing dd())

When something “isn’t working,” capture:
- exact exception message + stack trace
- request/transition context (assetId, preset, transition)
- persisted state snapshot (marking, fields relevant to the transition)
- the smallest reproduction command/test

Then fix via tests + logging, not `dd()`.

---

## Open decisions (to finalize before further refactor)

- Canonical identity: ULID/UUID only, or also content-hash-based storage keys
- Exact preset list and mapping rules (image + audio)
- Where originals are archived (local + object storage policy)
- CDN/proxy caching plan in front of imgproxy
- Timeline for removing legacy tables/models
