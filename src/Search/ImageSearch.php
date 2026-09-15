<?php

declare(strict_types=1);

namespace App\Search;

use App\Entity\Asset;
use App\Serializer\AssetNormalizer;
use App\Service\AssetRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Survos\SearchBundle\Attribute\AsSearch;
use Survos\SearchBundle\Search\AbstractSearch;
use Survos\SearchBundle\Twig\Components\Facet\RangeSlider;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * The pan-image search: every Asset in one Elasticsearch index (mediary_image), searched by its
 * AI claims, detected objects, OCR and source metadata.
 *
 * Build with `bin/console elastic:index:rebuild image`. elastic-bundle's Doctrine listener keeps it
 * current; AssetSearch (Postgres BM25) stays as the database-only alternative.
 */
#[AsSearch(index: Asset::class, name: self::NAME, adapter: 'es')]
final class ImageSearch extends AbstractSearch
{
    public const string NAME = 'image';

    private ?Serializer $serializer = null;

    public function __construct(
        private readonly AssetRegistry $assetRegistry,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function build(array $options = []): void
    {
        $facetFields = [];
        foreach (AssetDocument::FACETS as $field => $label) {
            $this->addFacet($field, $label);
            $facetFields[$field] = (AssetDocument::MAPPINGS[$field]['type'] ?? null) === 'text' ? $field.'.keyword' : $field;
        }
        $this->addFacet('faceCount', 'Faces', RangeSlider::class);
        $facetFields['faceCount'] = 'faceCount';

        $this
            ->addAvailableSort('createdAt:desc', 'Newest')
            ->addAvailableSort('createdAt:asc', 'Oldest')
            ->addAvailableSort('size:desc', 'Largest')
            ->setAvailableHitsPerPage([24, 48, 96]);

        $this->setAdapterParameters([
            'index' => self::NAME,
            'idField' => 'id',
            'mappings' => AssetDocument::MAPPINGS,
            'searchFields' => AssetDocument::SEARCH_FIELDS,
            'sourceFields' => array_keys(AssetDocument::MAPPINGS),
            'facetFields' => $facetFields,
            'sortFields' => ['createdAt' => 'createdAt', 'size' => 'size'],
            'maxFacetValues' => 50,
            'documentProvider' => $this->assets(...),
            'documentMapper' => $this->toDocument(...),
        ]);
    }

    /**
     * Every Asset, with the two relations AssetNormalizer reads fetched in the same query (lazy
     * loading cost two queries per asset). Keyset pages by primary key, because pdo_pgsql buffers a
     * whole result set: one toIterable() over a million assets fetched everything before the first
     * row and ran out of memory. The unit of work is cleared after every page.
     *
     * @return \Generator<int, Asset>
     */
    public function assets(int $pageSize = 1000): \Generator
    {
        $last = '';
        do {
            $page = $this->entityManager->createQuery(
                'SELECT a, mr, im FROM '.Asset::class.' a LEFT JOIN a.mediaRecord mr LEFT JOIN a.iiifManifestEntity im WHERE a.id > :last ORDER BY a.id ASC'
            )->setParameter('last', $last)->setMaxResults($pageSize)->getResult();
            foreach ($page as $asset) {
                $last = $asset->id;
                yield $asset;
            }
            $this->entityManager->clear();
        } while (count($page) === $pageSize);
    }

    /** @return array<string, mixed> */
    public function toDocument(Asset $asset): array
    {
        // AssetNormalizer adds the AI projection, the signed thumbnail and the IIIF fields. Not the
        // app's serializer: its API Platform normalizers retained ~17 KB per asset, which killed a
        // million-row rebuild at about 50k documents. This minimal chain stays flat.
        $this->serializer ??= new Serializer([
            new AssetNormalizer($this->assetRegistry),
            new DateTimeNormalizer(),
            new ObjectNormalizer(new ClassMetadataFactory(new AttributeLoader())),
        ]);
        $normalized = $this->serializer->normalize($asset, 'array', ['groups' => ['asset.read']]);
        $document = array_intersect_key(is_array($normalized) ? $normalized : [], AssetDocument::MAPPINGS);

        $document['id'] = $asset->id;
        $document['provider'] = $asset->provider;
        $document['dataset'] = $asset->dataset;
        $document['originalUrl'] = $asset->originalUrl;
        $document['claimCaption'] = $asset->claimCaption;
        $document['claimProse'] = $asset->claimProse;
        $document['claimSubjects'] = $asset->claimSubjects;
        $document['claimType'] = $asset->claimType;
        $document['aiOcrText'] ??= $asset->localOcrText;
        // Keyword-mapped fields must hold scalars or lists of scalars, never objects.
        foreach (['classification', 'objectIdentifiers', 'subjects', 'claimSubjects', 'aiKeywords', 'aiPeople', 'aiPlaces', 'aiOrganisations', 'aiSubjects'] as $field) {
            if (isset($document[$field])) {
                $document[$field] = self::flatStrings($document[$field]);
            }
        }

        return array_filter($document, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /** @return list<string> */
    private static function flatStrings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        array_walk_recursive($value, static function (mixed $item, int|string $key) use (&$out): void {
            if (is_string($item) && $item !== '' && !is_string($key)) {
                $out[] = $item;
            } elseif (is_string($item) && in_array($key, ['label', 'name', 'value'], true)) {
                $out[] = $item;
            }
        });

        return array_values(array_unique($out));
    }
}
