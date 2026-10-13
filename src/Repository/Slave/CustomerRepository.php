<?php

namespace App\Repository\Slave;

use App\Entity\Slave\Customer;
use App\Repository\Trait\RepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Customer>
 */
class CustomerRepository extends ServiceEntityRepository
{
    use RepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }

    /** 11. Elenco clienti dell'agenzia filtrato (filtri da FilterService::customerFilter). */
    public function queryAllFiltered(array $filters): Query
    {
        $qb = $this->createQueryBuilder('c')->where('1=1');
        $params = [];

        if ($filters['name'] !== '') {
            $qb->andWhere('c.name LIKE :name');
            $params['name'] = '%' . $filters['name'] . '%';
        }
        if ($filters['surname'] !== '') {
            $qb->andWhere('c.surname LIKE :surname');
            $params['surname'] = '%' . $filters['surname'] . '%';
        }
        if ($filters['fiscal'] !== '') {
            $qb->andWhere('c.fiscalCode LIKE :fiscal OR c.vatNumber LIKE :fiscal');
            $params['fiscal'] = '%' . $filters['fiscal'] . '%';
        }
        if ($filters['address'] !== '') {
            $qb->andWhere('c.address LIKE :address OR c.city LIKE :address OR c.zip LIKE :address');
            $params['address'] = '%' . $filters['address'] . '%';
        }

        foreach ($params as $key => $value) {
            $qb->setParameter($key, $value);
        }
        $this->applySort($qb, $filters, ['c.name', 'c.surname', 'c.fiscalCode', 'c.city'], 'c.surname');

        return $qb->getQuery();
    }

    /**
     * Cliente con lo stesso codice fiscale: serve a non duplicare l'anagrafica
     * quando la stessa persona torna in un'altra pratica.
     */
    public function findOneByFiscalCode(string $fiscalCode): ?Customer
    {
        if (trim($fiscalCode) === '') {
            return null;
        }

        return $this->createQueryBuilder('c')
            ->andWhere('UPPER(c.fiscalCode) = :fc')
            ->setParameter('fc', mb_strtoupper(trim($fiscalCode)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
