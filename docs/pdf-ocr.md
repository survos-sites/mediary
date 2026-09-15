# PDF OCR through Mediary

Task `ocr_pdf` registers the original PDF with PDF Tools, reads existing text per
page, and invokes Tesseract only for pages with no text. Page operations are cached
by PDF Tools. Mediary retains its existing Messenger task queue, claim persistence,
and completion webhook; no persistent JPEG collection is created.

## Configuration and release

Publish the mono changes first (`a8311429`: data-contracts, media-bundle,
folio-bundle), then update dependent applications. This branch's application
changes and the PDF Tools changes also need release; publishing mono alone does
not install the new Mediary task.

Set on Mediary and its workers:

```
PDFTOOLS_BASE_URI=https://pdf-tools.survos.com
PDFTOOLS_TOKEN=<existing service token>
MEDIARY_OWNED_PDF_HOSTS=nabolom.fsn1.your-objectstorage.com
```

For a local worker, PDFTOOLS_BASE_URI can be http://127.0.0.1:5001. The token must
match that service. The owned-host setting is an exact HTTPS host allowlist under
operator control. On these hosts Mediary adopts the existing PDF URL rather than
uploading a duplicate archive object. PDF Tools validates/caches the PDF. PDF
metadata also comes from PDF Tools, independently of imgproxy.

Keep the `asset.ai.task` and `webhook` consumers running alongside the existing
archive/info workflow consumers. `ocr_language` is an explicit Tesseract hint in
the per-URL source metadata. It is not an assertion about document language.
The production PDF Tools image includes English and Spanish trained data.

After publishing the matching Mediary code, set NBCORR_PDF_OCR=1 on Harvest and
its workers, then run `php bin/console dataset:register mus/nbcorr --rebuild`.
The singleton opts in document rows and requests `ocr_pdf` with `spa+eng`.
Until then its opt-in stays off so no unknown task reaches the old server.

## Text ownership

- Durable authority: Mediary claims (`ai:ocrText` and indexed `ai:ocrPage`).
- PDF Tools coordinates and recognition results are retained in page claim values.
- Folio page.text is a projection matched by source media ID + zero-based page index.
- Folio row.ocrText is a projection of the combined transcript for search/chat.
- Legacy localOcrText/context.ocr are not populated by this task.

A failed claim save now propagates to Messenger instead of falsely completing the
job. PDF OCR re-enters PDF Tools' cached page operations on retries, so a sidecar
cache hit cannot bypass recovery of the durable claims.

## Verification (2026-09-15)

The real ANB-C-04 PDF yielded 579 text characters and seven blocks with word boxes
using local Tesseract Spanish. It contains recognition errors (1954 became 1054),
so this is machine transcription, not authoritative catalog metadata.

Mixed embedded/scanned PDF tests verify OCR only runs for the scanned page;
HTTP failure propagates for retry. The owned-source test verifies no archive upload
or image probe is touched. Folio's SQLite regression verifies distinct PDF page
texts and the combined search transcript, with unchanged image OCR behavior.
Harvest rebuilt 10,076 page records for the existing 889 correspondence folders.

## Activation (2026-09-15)

Deployed Mediary commit `19e433b` with data-contracts 2.30.0 and PDF Tools
commit `10d88c0` to fsn1. Production workers and the web endpoint passed deploy
health checks; production PDF OCR has not yet been exercised end-to-end.

Harvest currently uses local Mediary on :8010 with callbacks to :8011. The real
ANB-C-10 pilot completed OCR, saved seven page claims and one combined transcript,
and delivered the completion callback. The rebuilt Folio and local Museado show
seven distinct page texts. Its source archive URL is the original Na Bolom S3 PDF.

Enabled NBCORR_PDF_OCR locally and registered all 889 folders (10,076 pages).
Local Mediary uses PDF Tools on :5001 with Spanish/English recognition; workers
are supervised and collection processing is underway. PDF Tools must remain running.

Fixed two integration gaps: Harvest read a different claims database, and automatic
enrichment/periodic previews bypassed the command's claims refresh. Development
now reads the same Mediary over its authenticated claims API; both build paths
refresh claims before conversion. A failed fetch now stops the build.

PDF page thumbnails render with the existing PDF.js document instead of sending
PDF URLs to imgproxy. Mono commit `d876d758` contains that fix; Museado commit
`f0f513b` connects PDF paging, the URL, thumbnails and the transcript. These last
viewer changes are local and still need the next library release/deployment.

