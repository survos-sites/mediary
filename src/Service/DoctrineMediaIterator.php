<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use SortDirection;

/** Bounded, deterministic keyset traversal, independent of what listeners do. */
final class DoctrineMediaIterator
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param class-string $class
     * @param list<string> $filters Exact field=value predicates, ANDed together.
     * @return \Generator<int|string, object>
     */
    public function iterate(string $class, array $filters, int $limit = 3, int $pageSize = 100): \Generator
    {
        if ($limit < 1 || $pageSize < 1) {
            throw new \InvalidArgumentException('Limit and page size must be positive.');
        }
        $meta = $this->em->getClassMetadata($class);
        if ($meta->isIdentifierComposite) {
            throw new \InvalidArgumentException('Iteration requires a single scalar identifier.');
        }
        $id = $meta->getSingleIdentifierFieldName();
        if (!$meta->hasField($id) || !in_array($meta->getTypeOfField($id), ['string', 'integer', 'smallint', 'bigint', 'guid'], true)) {
            throw new \InvalidArgumentException('Iteration requires a string or integer identifier.');
        }
        $base = $this->em->createQueryBuilder()->select('e')->from($class, 'e')->orderBy('e.'.$id, SortDirection::Ascending);
        foreach ($filters as $i => $filter) {
            $parts = explode('=', $filter, 2);
            $field = $parts[0];
            if (count($parts) !== 2 || !$meta->hasField($field)) {
                throw new \InvalidArgumentException(sprintf('Invalid filter "%s"; use a mapped scalar field=value.', $filter));
            }
            $type = $meta->getTypeOfField($field);
            if (!in_array($type, ['string', 'text', 'integer', 'smallint', 'bigint', 'boolean', 'float', 'decimal', 'guid'], true)) {
                throw new \InvalidArgumentException(sprintf('Filtering field "%s" of type "%s" is not supported.', $field, $type));
            }
            $value = $parts[1];
            if ($value === 'null') {
                $base->andWhere('e.'.$field.' IS NULL');
                continue;
            }
            if ($type === 'boolean') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($value === null) {
                    throw new \InvalidArgumentException('Boolean filters require true/false or 1/0.');
                }
            } elseif (in_array($type, ['integer', 'smallint', 'bigint'], true) && !preg_match('/^-?\d+$/D', $value)) {
                throw new \InvalidArgumentException('Integer filters require an integer value.');
            } elseif (in_array($type, ['float', 'decimal'], true) && !is_numeric($value)) {
                throw new \InvalidArgumentException('Numeric filters require a number.');
            }
            $base->andWhere('e.'.$field.' = :filter'.$i)->setParameter('filter'.$i, $value, $type);
        }

        $last = null;
        $count = 0;
        while ($count < $limit) {
            $qb = clone $base;
            if ($last !== null) {
                $qb->andWhere('e.'.$id.' > :cursor')->setParameter('cursor', $last, $meta->getTypeOfField($id));
            }
            $page = $qb->setMaxResults(min($pageSize, $limit - $count))->getQuery()->getResult();
            if ($page === []) {
                break;
            }
            foreach ($page as $entity) {
                $last = $meta->getIdentifierValues($entity)[$id];
                ++$count;
                yield $last => $entity;
                // This utility never flushes; listeners own any explicitly requested persistence.
                $this->em->detach($entity);
            }
        }
    }
}
