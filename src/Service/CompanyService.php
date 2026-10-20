<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\Document;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * L'agenzia (Company) nel contesto della richiesta e i dati che la riguardano.
 * Con il database unico l'agenzia corrente è quella dell'utente dello staff collegato.
 */
class CompanyService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly Security $security,
    ) {
    }

    /** Agenzia dell'utente collegato: null per amministratori, notai e visitatori. */
    public function getCurrentCompany(): ?Company
    {
        $user = $this->security->getUser();

        return $user instanceof User && $user->isAgencyUser() ? $user->getCompany() : null;
    }

    /**
     * Indirizzo a cui scrivere al cliente (14.5 / 14.6 / 14.9). Il Company non ha un campo
     * e-mail: si usa il primo amministratore attivo dell'agenzia, altrimenti un utente
     * attivo qualsiasi. Null se l'agenzia non ha utenti con un'e-mail.
     */
    public function getPrimaryContactEmail(Company $company): ?string
    {
        $staff = $this->users->findStaff($company, true);

        foreach ($staff as $user) {
            if ($user->isAdmin() && $user->getEmail()) {
                return $user->getEmail();
            }
        }

        foreach ($staff as $user) {
            if ($user->getEmail()) {
                return $user->getEmail();
            }
        }

        return null;
    }

    /**
     * 7.1.8 / 9.1 Spazio occupato (MB) dall'agenzia: la somma dei file caricati nelle sue pratiche.
     */
    public function getStorageUsedMb(Company $company): float
    {
        $bytes = (int) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(d.sizeBytes), 0)')
            ->from(Document::class, 'd')
            ->join('d.practiceDocument', 'pd')
            ->join('pd.practice', 'p')
            ->where('p.company = :company')
            ->setParameter('company', $company)
            ->getQuery()
            ->getSingleScalarResult();

        return round($bytes / 1048576, 1);
    }
}
