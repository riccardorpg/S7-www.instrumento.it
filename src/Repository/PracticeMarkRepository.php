<?php

namespace App\Repository;

use App\Entity\PracticeMark;
use App\Repository\Trait\RepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PracticeMark>
 */
class PracticeMarkRepository extends ServiceEntityRepository
{
    use RepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PracticeMark::class);
    }

    /** 13.2 Elenco contrassegni filtrato (filtri da FilterService::colorValueFilter). */
    public function queryAllFiltered(array $filters): Query
    {
        $qb = $this->createQueryBuilder('m')->where('1=1');
        $params = [];

        if ($filters['value'] !== '') {
            $qb->andWhere('m.value LIKE :value');
            $params['value'] = '%' . $filters['value'] . '%';
        }

        foreach ($params as $key => $value) {
            $qb->setParameter($key, $value);
        }
        $this->applySort($qb, $filters, ['m.value'], 'm.value');

        return $qb->getQuery();
    }

    /** True se esiste già un contrassegno con lo stesso valore (case-insensitive). */
    public function valueExists(string $value, ?int $exceptId = null): bool
    {
        $qb = $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('LOWER(m.value) = :v')
            ->setParameter('v', mb_strtolower($value));

        if ($exceptId !== null) {
            $qb->andWhere('m.id <> :id')->setParameter('id', $exceptId);
        }

        return ((int) $qb->getQuery()->getSingleScalarResult()) > 0;
    }
}
