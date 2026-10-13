<?php

namespace App\Repository\Master;

use App\Entity\Master\ExternalAccount;
use App\Repository\Trait\RepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExternalAccount>
 */
class ExternalAccountRepository extends ServiceEntityRepository
{
    use RepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalAccount::class);
    }

    /** 8. Elenco account esterni filtrato (filtri da FilterService::externalAccountFilter). */
    public function queryAllFiltered(array $filters): Query
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.company', 'co')->addSelect('co')
            ->where('1=1');
        $params = [];

        foreach (['name' => 'a.name', 'surname' => 'a.surname', 'email' => 'a.email', 'phone' => 'a.phone'] as $key => $field) {
            if ($filters[$key] !== '') {
                $qb->andWhere(sprintf('%s LIKE :%s', $field, $key));
                $params[$key] = '%' . $filters[$key] . '%';
            }
        }
        $companyIds = array_values(array_filter(explode(',', $filters['company'])));
        if ($companyIds !== []) {
            $qb->andWhere('co.id IN (:companies)');
            $params['companies'] = $companyIds;
        }

        foreach ($params as $key => $value) {
            $qb->setParameter($key, $value);
        }
        $this->applySort($qb, $filters, ['a.name', 'a.surname', 'a.email', 'a.phone', 'co.name'], 'a.surname');

        return $qb->getQuery();
    }
}
