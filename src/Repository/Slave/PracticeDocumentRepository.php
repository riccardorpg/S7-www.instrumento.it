<?php

namespace App\Repository\Slave;

use App\Entity\Slave\Practice;
use App\Entity\Slave\PracticeDocument;
use App\Repository\Trait\RepositoryTrait;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PracticeDocument>
 */
class PracticeDocumentRepository extends ServiceEntityRepository
{
    use RepositoryTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PracticeDocument::class);
    }

    /**
     * Scrivania del notaio: documenti "da verificare" (caricati, in attesa del suo controllo) delle
     * pratiche assegnate a lui, escluse le archiviate e le righe non richieste, per pratica.
     *
     * @return PracticeDocument[]
     */
    public function findToVerifyForNotary(string $email): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.practice', 'p')->addSelect('p')
            ->andWhere('r.status = :toVerify')->setParameter('toVerify', PracticeDocument::STATUS_TO_VERIFY)
            ->andWhere('r.visible = true')
            ->andWhere('LOWER(p.notaryEmail) = :email')->setParameter('email', mb_strtolower(trim($email)))
            ->andWhere('p.status <> :archived')->setParameter('archived', Practice::STATUS_ARCHIVED)
            ->orderBy('p.number', 'ASC')
            ->addOrderBy('r.priority', 'ASC')
            ->addOrderBy('r.label', 'ASC')
            ->getQuery()->getResult();
    }
}
