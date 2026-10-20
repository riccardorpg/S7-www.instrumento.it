<?php

namespace App\Repository;

use App\Entity\Practice;
use App\Entity\PracticeAlert;
use App\Entity\User;
use App\Repository\Trait\RepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PracticeAlert>
 */
class PracticeAlertRepository extends ServiceEntityRepository
{
    use RepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PracticeAlert::class);
    }

    /**
     * Scrivania del notaio: i suoi avvisi e quelli che l'agenzia ha scelto di mostrargli, sulle pratiche assegnate
     * a lui, escluse le archiviate, i più imminenti per primi.
     *
     * @return PracticeAlert[]
     */
    public function findForNotary(User $notary, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.practice', 'p')->addSelect('p')
            ->andWhere('a.visibleToNotary = true OR a.byNotary = true')
            ->andWhere('p.notary = :notary')->setParameter('notary', $notary)
            ->andWhere('p.status <> :archived')->setParameter('archived', Practice::STATUS_ARCHIVED)
            ->orderBy('a.remindAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
