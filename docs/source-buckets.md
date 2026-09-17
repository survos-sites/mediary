# Source buckets: private originals used in place

Some collections are on no website: PATH Tobacco Ads, Na Bolom's scans, anything found on a drive.
Their originals have to be in S3 anyway, as the off-machine copy of the platform vault. Archiving
them the usual way would mean a public bucket so mediary can GET them, then a second copy under
`orig/` in ours.

A source bucket skips both. Mediary registers the object where it is:

| Step | Normal asset | Source-bucket asset |
|---|---|---|
| `/batch` url (identity, asset id) | public URL | `https://<bucket>.fsn1.your-objectstorage.com/<key>`, which answers 403 to the public |
| archive | GET, sha256, write `orig/…` to our bucket | HEAD with our keys; nothing downloaded or written |
| `storageBucket` / `storageKey` / `storageBackend` | null / `orig/…` / `archive` | `survos-platform` / `vault/…` / `source` |
| imgproxy, `/info` | `s3://<AWS_S3_BUCKET_NAME>/orig/…` | `s3://survos-platform/vault/…` |
| HTTP readers (AI tasks, OCR, thumbhash) | `archiveUrl` (our bucket is public-read) | presigned URL (`AssetPresigner`) |
| delete | removes the `orig/` object | leaves the object alone: it is the only remote copy |

Code: `App\Service\SourceBuckets` (URL → bucket/key, HEAD), `AssetWorkflow::useSourceObject()`,
`AssetRegistry::bucket()`. Migration `Version20260917160000` adds `asset.storage_bucket`.

## Configuration

Operator configuration, never a caller hint. Each entry is a bucket, optionally with a key prefix the
object must sit under:

```
MEDIARY_SOURCE_BUCKETS=survos-platform/vault/
```

Mediary's S3 keys must be able to HEAD (and presign GETs from) the bucket, and imgproxy's keys must
be able to read it. The Hetzner project keys reach every bucket in the project; verified 2026-09-17
for `survos-platform` with the production imgproxy: `s3://` source 200, the https URL 403
("Source is unreachable").

Only https URLs without a query string on the S3 endpoint match (virtual-host or path-style). A
presigned URL is not an identity: it changes with every signature, and each would become a new asset.

## Adding a collection (harvest side)

1. Copy and verify the originals into the vault, then push the dataset dir to the private vault:
   `bin/vault-local-collection.sh <source-dir> <provider/code> --push` (harvest).
2. The singleton writes each image's vault URL
   (`https://survos-platform.fsn1.your-objectstorage.com/vault/<provider>/<code>/originals/<file>`)
   into page.jsonl, and not into `thumbnailUrl` or `largeImageUrl`: browsers and zm fetch those
   directly and would get 403. See `App\Singleton\PathTobaccoAds`.

## Existing collections

`nabolom` is a public-read bucket used through `MEDIARY_OWNED_PDF_HOSTS` for PDFs, and as plain public
URLs for images, which mediary copies. Moving it to this pattern means adding `nabolom` here,
re-registering the image assets, deleting their `orig/` copies, and only then making the bucket private.
