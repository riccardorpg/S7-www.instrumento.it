<?php

namespace App\Repository;

use App\Entity\DocumentType;
use App\Repository\Trait\RepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DocumentType>
 */
class DocumentTypeRepository extends ServiceEntityRepository
{
    use RepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentType::class);
    }

    /** 13.1 Elenco tipi di documento filtrato (filtri da FilterService::documentTypeFilter). */
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
        $this->applySort($qb, $filters, ['t.priority', 't.value'], 't.priority');

        return $qb->getQuery();
    }

    /**
     * 13.1.1 Elenco completo nell'ordine deciso col drag & drop.
     *
     * @return DocumentType[]
     */
    public function findOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.priority', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Priorità successiva all'ultima occupata: serve ad accodare i nuovi tipi. */
    public function nextPriority(): int
    {
        $max = $this->createQueryBuilder('t')
            ->select('MAX(t.priority)')
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? 0 : ((int) $max) + 1;
    }

    /** True se esiste già un tipo con lo stesso valore (confronto case-insensitive). */
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
