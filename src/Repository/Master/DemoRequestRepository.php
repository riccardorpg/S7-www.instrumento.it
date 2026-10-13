<?php

namespace App\Repository\Master;

use App\Entity\Master\DemoRequest;
use App\Repository\Trait\RepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemoRequest>
 */
class DemoRequestRepository extends ServiceEntityRepository
{
    use RepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemoRequest::class);
    }

    /**
     * 6.1.1 Nuove richieste in arrivo (ancora da evadere).
     *
     * @return DemoRequest[]
     */
    public function findPending(): array
    {
        return $this->findBy(['status' => DemoRequest::STATUS_NEW], ['createdAt' => 'DESC']);
    }

    /**
     * Richieste scartate (archivio, per poterle recuperare).
     *
     * @return DemoRequest[]
     */
    public function findRejected(): array
    {
        return $this->findBy(['status' => DemoRequest::STATUS_REJECTED], ['processedAt' => 'DESC']);
    }

    public function countPending(): int
    {
        return $this->count(['status' => DemoRequest::STATUS_NEW]);
    }
}
