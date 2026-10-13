<?php

namespace App\Repository\Slave;

use App\Entity\Slave\Permission;
use App\Repository\Trait\RepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Permission>
 */
class PermissionRepository extends ServiceEntityRepository
{
    use RepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Permission::class);
    }

    /**
     * Catalogo nell'ordine in cui va mostrato nella matrice.
     *
     * @return Permission[]
     */
    public function findOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.priority', 'ASC')
            ->addOrderBy('p.value', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
