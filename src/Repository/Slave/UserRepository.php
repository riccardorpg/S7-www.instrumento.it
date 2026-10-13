<?php

namespace App\Repository\Slave;

use App\Entity\Slave\User;
use App\Repository\Trait\RepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    use RepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * 10.1 Elenco staff filtrato (filtri da FilterService::staffFilter).
     *
     * @param string[] $roles ruoli ammessi (User::STAFF_ROLES): quelli fuori elenco si ignorano
     */
    public function queryStaffFiltered(array $filters, array $roles): Query
    {
        $qb = $this->createQueryBuilder('u')->where('1=1');
        $params = [];

        foreach (['name', 'surname', 'email'] as $field) {
            if ($filters[$field] !== '') {
                $qb->andWhere(sprintf('u.%s LIKE :%s', $field, $field));
                $params[$field] = '%' . $filters[$field] . '%';
            }
        }
        if ($filters['active'] !== '') {
            $qb->andWhere('u.active = :active');
            $params['active'] = $filters['active'] === '1';
        }
        $selected = array_values(array_intersect(array_filter(explode(',', $filters['role'])), $roles));
        if ($selected !== []) {
            $qb->andWhere('u.staffRole IN (:roles)');
            $params['roles'] = $selected;
        }

        foreach ($params as $key => $value) {
            $qb->setParameter($key, $value);
        }
        $this->applySort($qb, $filters, ['u.name', 'u.surname', 'u.email', 'u.staffRole'], 'u.surname');

        return $qb->getQuery();
    }

    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }
}
