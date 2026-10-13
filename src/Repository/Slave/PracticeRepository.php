<?php

namespace App\Repository\Slave;

use App\Entity\Slave\Practice;
use App\Repository\Trait\RepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Practice>
 */
class PracticeRepository extends ServiceEntityRepository
{
    use RepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Practice::class);
    }

    /**
     * 12.1 Elenco pratiche dell'agenzia filtrato (filtri da FilterService::practiceFilter).
     * 12.3.3 Con $onlyStaffId si vedono solo le pratiche in cui quell'utente è abilitato.
     */
    public function queryAgencyFiltered(array $filters, ?int $onlyStaffId = null): Query
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.seller', 's')->addSelect('s')
            ->leftJoin('p.buyer', 'b')->addSelect('b')
            ->leftJoin('p.mark', 'm')->addSelect('m')
            ->where('1=1');
        $params = [];

        if ($onlyStaffId !== null) {
            $qb->leftJoin('p.staff', 'ps')->andWhere('ps.id = :me');
            $params['me'] = $onlyStaffId;
        }
        $statuses = array_values(array_intersect(array_filter(explode(',', $filters['status'])), array_keys(Practice::STATUSES)));
        if ($statuses !== []) {
            $qb->andWhere('p.status IN (:statuses)');
            $params['statuses'] = $statuses;
        }
        if ($filters['createdStart'] !== null) {
            $qb->andWhere('p.createdAt >= :createdStart');
            $params['createdStart'] = $filters['createdStart'];
        }
        if ($filters['createdEnd'] !== null) {
            $qb->andWhere('p.createdAt <= :createdEnd');
            $params['createdEnd'] = $filters['createdEnd'];
        }
        if ($filters['mortgage'] !== '') {
            $qb->andWhere('p.mortgage = :mortgage');
            $params['mortgage'] = $filters['mortgage'] === '1';
        }
        if ($filters['seller'] !== '') {
            $qb->andWhere("CONCAT(COALESCE(s.name, ''), ' ', COALESCE(s.surname, '')) LIKE :seller");
            $params['seller'] = '%' . $filters['seller'] . '%';
        }
        if ($filters['buyer'] !== '') {
            $qb->andWhere("CONCAT(COALESCE(b.name, ''), ' ', COALESCE(b.surname, '')) LIKE :buyer");
            $params['buyer'] = '%' . $filters['buyer'] . '%';
        }
        if ($filters['number'] !== '') {
            $qb->andWhere('p.number LIKE :number');
            $params['number'] = '%' . $filters['number'] . '%';
        }
        if ($filters['address'] !== '') {
            $qb->andWhere('p.address LIKE :address');
            $params['address'] = '%' . $filters['address'] . '%';
        }

        foreach ($params as $key => $value) {
            $qb->setParameter($key, $value);
        }
        $this->applySort($qb, $filters, ['p.status', 'p.createdAt', 'p.mortgage', 'p.number', 'p.address'], 'p.createdAt', 'desc');

        return $qb->getQuery();
    }

    /**
     * 17.1 Elenco pratiche dell'agenzia visto dal notaio (filtri da
     * FilterService::notaryPracticeFilter): l'accesso all'agenzia è già verificato.
     */
    public function queryNotaryFiltered(array $filters): Query
    {
        $qb = $this->createQueryBuilder('p')->where('1=1');
        $params = [];

        if ($filters['q'] !== '') {
            $qb->andWhere('p.number LIKE :q OR p.subject LIKE :q');
            $params['q'] = '%' . $filters['q'] . '%';
        }
        // Le parti sono clienti dell'agenzia (11): il filtro cerca su nome e cognome.
        if ($filters['buyer'] !== '') {
            $qb->leftJoin('p.buyer', 'bc')->andWhere("CONCAT(COALESCE(bc.name, ''), ' ', COALESCE(bc.surname, '')) LIKE :buyer");
            $params['buyer'] = '%' . $filters['buyer'] . '%';
        }
        if ($filters['seller'] !== '') {
            $qb->leftJoin('p.seller', 'sc')->andWhere("CONCAT(COALESCE(sc.name, ''), ' ', COALESCE(sc.surname, '')) LIKE :seller");
            $params['seller'] = '%' . $filters['seller'] . '%';
        }
        if ($filters['status'] !== '') {
            $qb->andWhere('p.status = :status');
            $params['status'] = $filters['status'];
        }

        foreach ($params as $key => $value) {
            $qb->setParameter($key, $value);
        }
        $this->applySort($qb, $filters, ['p.number', 'p.mortgage', 'p.status', 'p.createdAt'], 'p.createdAt', 'desc');

        return $qb->getQuery();
    }

}
