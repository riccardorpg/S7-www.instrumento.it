<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\Practice;
use App\Entity\PracticeDocument;
use App\Entity\User;
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
     * Scrivania dell'agenzia: documenti che il notaio ha rimandato all'agenzia ("da verificare
     * agenzia"), escluse le pratiche archiviate e le righe non richieste, per pratica. Con
     * $onlyStaffId solo le pratiche in cui quell'utente è abilitato (chi non è amministratore).
     *
     * @return PracticeDocument[]
     */
    public function findToVerifyForAgency(Company $company, ?int $onlyStaffId = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->join('r.practice', 'p')->addSelect('p')
            ->andWhere('p.company = :company')->setParameter('company', $company)
            ->andWhere('r.status = :toVerify')->setParameter('toVerify', PracticeDocument::STATUS_TO_VERIFY_AGENCY)
            ->andWhere('r.visible = true')
            ->andWhere('p.status <> :archived')->setParameter('archived', Practice::STATUS_ARCHIVED)
            ->orderBy('p.number', 'ASC')
            ->addOrderBy('r.priority', 'ASC')
            ->addOrderBy('r.label', 'ASC');
        if ($onlyStaffId !== null) {
            $qb->join('p.staff', 'ps')->andWhere('ps.id = :me')->setParameter('me', $onlyStaffId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Scrivania del notaio: documenti "da verificare" (caricati, in attesa del suo controllo) delle
     * pratiche assegnate a lui, escluse le archiviate e le righe non richieste, per pratica.
     *
     * @return PracticeDocument[]
     */
    public function findToVerifyForNotary(User $notary): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.practice', 'p')->addSelect('p')
            ->andWhere('r.status = :toVerify')->setParameter('toVerify', PracticeDocument::STATUS_TO_VERIFY)
            ->andWhere('r.visible = true')
            ->andWhere('p.notary = :notary')->setParameter('notary', $notary)
            ->andWhere('p.status <> :archived')->setParameter('archived', Practice::STATUS_ARCHIVED)
            ->orderBy('p.number', 'ASC')
            ->addOrderBy('r.priority', 'ASC')
            ->addOrderBy('r.label', 'ASC')
            ->getQuery()->getResult();
    }
}
