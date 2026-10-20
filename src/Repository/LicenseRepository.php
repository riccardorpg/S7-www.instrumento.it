<?php

namespace App\Repository;

use App\Entity\License;
use App\Entity\LicenseType;
use App\Repository\Trait\RepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<License>
 */
class LicenseRepository extends ServiceEntityRepository
{
    use RepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, License::class);
    }

    /**
     * Licenze in uso (assegnate a un cliente, valide o no) filtrate (filtri da FilterService::licenseFilter),
     * per la scheda "Licenze occupate". Le libere la loro scheda le raggruppa per tipo (countFreeByType).
     */
    public function queryAllFiltered(array $filters): Query
    {
        $qb = $this->createQueryBuilder('l')
            ->join('l.type', 't')->addSelect('t')
            ->leftJoin('l.company', 'c')->addSelect('c')
            ->where('1=1');
        $params = [];

        $qb->andWhere('c.id IS NOT NULL');

        $types = array_values(array_filter(explode(',', $filters['type'])));
        if ($types !== []) {
            $qb->andWhere('t.id IN (:types)');
            $params['types'] = $types;
        }
        if ($filters['company'] !== '') {
            $qb->andWhere('c.name LIKE :company OR c.code LIKE :company');
            $params['company'] = '%' . $filters['company'] . '%';
        }
        if ($filters['startedStart'] !== null && $filters['startedEnd'] !== null) {
            $qb->andWhere('l.startDate BETWEEN :startedStart AND :startedEnd');
            $params['startedStart'] = $filters['startedStart'];
            $params['startedEnd'] = $filters['startedEnd'];
        }
        if ($filters['expiryStart'] !== null && $filters['expiryEnd'] !== null) {
            $qb->andWhere('l.expirationDate BETWEEN :expiryStart AND :expiryEnd');
            $params['expiryStart'] = $filters['expiryStart'];
            $params['expiryEnd'] = $filters['expiryEnd'];
        }
        if ($filters['createdStart'] !== null && $filters['createdEnd'] !== null) {
            $qb->andWhere('l.creationDate BETWEEN :createdStart AND :createdEnd');
            $params['createdStart'] = $filters['createdStart'];
            $params['createdEnd'] = $filters['createdEnd'];
        }

        // Stato = interruttore dell'amministratore (attiva prima, disattivata dopo); a parità, per scadenza.
        $qb->addSelect('CASE WHEN l.isAdminActive = false THEN 1 ELSE 0 END AS HIDDEN statusSort');

        foreach ($params as $key => $value) {
            $qb->setParameter($key, $value);
        }
        $this->applySort($qb, $filters, ['statusSort', 't.type', 'l.creationDate', 'l.startDate', 'l.expirationDate', 'c.name'], 'l.creationDate', 'desc');
        if ($filters['sortField'] === 'statusSort') {
            $qb->addOrderBy('l.expirationDate', $filters['sortOrder'] === 'desc' ? 'DESC' : 'ASC');
        }

        return $qb->getQuery();
    }

    /** Quante licenze per scheda, per i contatori sulle linguette. */
    public function countByTab(): array
    {
        $row = $this->createQueryBuilder('l')
            ->select('SUM(CASE WHEN c.id IS NULL THEN 1 ELSE 0 END) AS libere, SUM(CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END) AS occupate')
            ->leftJoin('l.company', 'c')
            ->getQuery()->getSingleResult();

        return ['occupate' => (int) $row['occupate'], 'libere' => (int) $row['libere']];
    }

    /** La prima licenza libera (la più vecchia) del tipo indicato, per assegnarla; null se non ce ne sono. */
    public function findFirstFreeByType(LicenseType $type): ?License
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.company', 'c')
            ->andWhere('c.id IS NULL')
            ->andWhere('l.type = :type')->setParameter('type', $type)
            ->orderBy('l.creationDate', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Quante licenze libere per tipo, per la scheda "Licenze libere".
     *
     * @return array<int, int> id del tipo => quantità (solo i tipi con almeno una licenza libera)
     */
    public function countFreeByType(): array
    {
        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.type) AS typeId, COUNT(l.id) AS qty')
            ->leftJoin('l.company', 'c')
            ->andWhere('c.id IS NULL')
            ->groupBy('l.type')
            ->getQuery()->getArrayResult();

        return array_column(array_map(static fn (array $r) => ['id' => (int) $r['typeId'], 'qty' => (int) $r['qty']], $rows), 'qty', 'id');
    }

    /**
     * Le ultime $limit licenze libere create di un tipo, da eliminare.
     *
     * @return License[]
     */
    public function findFreeByType(LicenseType $type, int $limit): array
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.company', 'c')
            ->andWhere('c.id IS NULL')
            ->andWhere('l.type = :type')->setParameter('type', $type)
            ->orderBy('l.creationDate', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
