<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The one place that shapes an asset "probe" payload.
 *
 * Two transports serve this same data and must not drift:
 *   - REST:     GET/POST /fetch/media/{id} and /fetch/media/by-ids ({@see \App\Controller\ApiMediaController})
 *   - JSON-RPC: probeAssets on /api/v1                             ({@see \App\RPC\V1\ProbeAssetsMethod})
 *
 * media-bundle's MediaBatchDispatcher::probe()/probeMany() is the client side of the REST
 * pair; the JSON-RPC method is the experimental replacement for probeMany().
 */
final readonly class AssetProbeService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param  list<string>              $ids
     * @return list<array<string,mixed>> in the order the assets come back from the DB
     */
    public function probeMany(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            $ids,
            static fn (mixed $v): bool => \is_string($v) && $v !== '',
        )));

        if ($ids === []) {
            return [];
        }

        /** @var list<Asset> $assets */
        $assets = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Asset::class, 'a')
            ->andWhere('a.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map($this->probe(...), $assets);
    }

    /**
     * Current known state for one asset, including variants and child derivatives.
     * OCR/AI results live in context, on either the parent or the children.
     *
     * @return array<string,mixed>
     */
    public function probe(Asset $asset): array
    {
        /** @var list<Asset> $children */
        $children = $this->em->getRepository(Asset::class)
            ->findBy(['parentKey' => $asset->id], ['pageNumber' => 'ASC']);

        $childRows = array_map(static fn (Asset $child): array => [
            'id'         => $child->id,
            'pageNumber' => $child->pageNumber,
            'marking'    => $child->marking,
            'mime'       => $child->mime,
            'archiveUrl' => $child->archiveUrl,
            'smallUrl'   => $child->smallUrl,
            'context'    => $child->context,
            'sourceMeta' => $child->sourceMeta,
        ], $children);

        return [
            'id'                => $asset->id,
            'source'            => (string) $asset->originalUrl,
            'marking'           => $asset->marking,
            'typeEstimate'      => $asset->context['type_estimate'] ?? null,
            'edgeAnalysis'      => $asset->context['edge_analysis'] ?? null,
            'hasTextLikely'     => $asset->context['has_text_likely'] ?? null,
            'typedLikely'       => $asset->context['typed_likely'] ?? null,
            'handwrittenLikely' => $asset->context['handwritten_likely'] ?? null,
            // Resized derivatives are served on the fly by imgproxy; see meta.smallUrl.
            'context'           => $asset->context,    // image-derived: OCR, thumbhash, colors, hash
            'sourceMeta'        => $asset->sourceMeta, // client-provided: dcterms:*, rights, ARK, IIIF
            // Promoted /info results. These live in context.info too, but a caller checking
            // "did enrichment run, and what did it find" should not have to dig through the
            // raw imgproxy payload -- faceCount in particular is the facet the UI uses.
            'faceCount'         => $asset->faceCount,
            'classification'    => $asset->classification,
            'objectIdentifiers' => $asset->objectIdentifiers,
            'meta'              => [
                'mimeType'    => $asset->mime,
                'width'       => $asset->width,
                'height'      => $asset->height,
                'size'        => $asset->size,
                'statusCode'  => $asset->statusCode,
                'storageKey'  => $asset->storageKey,
                'archiveUrl'  => $asset->archiveUrl,
                'smallUrl'    => $asset->smallUrl,
                'contentHash' => $asset->contentHash,
                'childCount'  => $asset->childCount,
                'hasOcr'      => $asset->hasOcr,
            ],
            'children'          => $childRows,
            'ocr'               => $asset->context['ocr'] ?? null,
            'ai'                => $asset->context['ai'] ?? null,
        ];
    }
}
