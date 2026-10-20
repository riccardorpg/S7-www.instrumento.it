<?php

namespace App\Repository;

use App\Entity\SpaceExtra;
use App\Repository\Trait\RepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SpaceExtra>
 */
class SpaceExtraRepository extends ServiceEntityRepository
{
    use RepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SpaceExtra::class);
    }

    /** @return SpaceExtra[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['space' => 'ASC']);
    }
}
