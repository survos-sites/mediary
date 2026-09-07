<?php

namespace App\Repository;

use App\Entity\Asset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\DataContracts\Util\MediaKeyService;
use Survos\DataContracts\Util\MediaIdentity;

/**
 * @extends ServiceEntityRepository<Asset>
 */
class AssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Asset::class);
    }

    //    /**
    //     * @return Asset[] Returns an array of Asset objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('a')
    //            ->andWhere('a.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('a.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

        public function findOneByUrl(string $originalUrl): ?Asset
        {
            return $this->find(MediaIdentity::idFromOriginalUrl($originalUrl));
        }

        /**
         * Batch lookup — one query instead of one findOneByUrl() round trip per URL.
         *
         * @param string[] $originalUrls
         * @return array<string, Asset> keyed by originalUrl
         */
        public function findByUrls(array $originalUrls): array
        {
            if ($originalUrls === []) {
                return [];
            }

            $ids = array_map(MediaIdentity::idFromOriginalUrl(...), $originalUrls);

            $rows = $this->createQueryBuilder('a')
                ->where('a.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->getQuery()
                ->getResult();

            $byUrl = [];
            foreach ($rows as $row) {
                $byUrl[$row->originalUrl] = $row;
            }

            return $byUrl;
        }
}
