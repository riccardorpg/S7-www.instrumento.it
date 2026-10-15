<?php

namespace App\Repository\Slave;

use App\Entity\Slave\Practice;
use App\Entity\Slave\PracticeAlert;
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
     * Scrivania del notaio: avvisi che l'agenzia ha scelto di mostrargli, sulle pratiche assegnate
     * a lui (e-mail del notaio sulla pratica), escluse le archiviate, i più imminenti per primi.
     *
     * @return PracticeAlert[]
     */
    public function findForNotary(string $email, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.practice', 'p')->addSelect('p')
            ->andWhere('a.visibleToNotary = true')
            ->andWhere('LOWER(p.notaryEmail) = :email')->setParameter('email', mb_strtolower(trim($email)))
            ->andWhere('p.status <> :archived')->setParameter('archived', Practice::STATUS_ARCHIVED)
            ->orderBy('a.remindAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }
}
