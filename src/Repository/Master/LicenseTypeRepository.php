<?php

namespace App\Repository\Master;

use App\Entity\Master\LicenseType;
use App\Repository\Trait\RepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LicenseType>
 */
class LicenseTypeRepository extends ServiceEntityRepository
{
    use RepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LicenseType::class);
    }

    /** @return LicenseType[] */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['spaceIncluded' => 'ASC', 'type' => 'ASC']);
    }
}
