<?php

namespace App\Repository;

use App\Entity\PracticeTag;
use App\Repository\Trait\RepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PracticeTag>
 */
class PracticeTagRepository extends ServiceEntityRepository
{
    use RepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PracticeTag::class);
    }

    /** 13.3 Elenco tag filtrato (filtri da FilterService::colorValueFilter). */
    public function queryAllFiltered(array $filters): Query
    {
        $qb = $this->createQueryBuilder('t')->where('1=1');
        $params = [];

        if ($filters['value'] !== '') {
            $qb->andWhere('t.value LIKE :value');
            $params['value'] = '%' . $filters['value'] . '%';
        }

        foreach ($params as $key => $value) {
            $qb->setParameter($key, $value);
        }
        $this->applySort($qb, $filters, ['t.value'], 't.value');

        return $qb->getQuery();
    }

    /** True se esiste già un tag con lo stesso valore (case-insensitive). */
    public function valueExists(string $value, ?int $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('LOWER(t.value) = :v')
            ->setParameter('v', mb_strtolower($value));

        if ($exceptId !== null) {
            $qb->andWhere('t.id <> :id')->setParameter('id', $exceptId);
        }

        return ((int) $qb->getQuery()->getSingleScalarResult()) > 0;
    }
}
