<?php

namespace App\Controller\Agency;

use App\Entity\Slave\Practice;
use App\Entity\Slave\PracticeAlert;
use App\Entity\Slave\PracticeDocument;
use App\Entity\Slave\User as StaffUser;
use App\Service\CompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/agenzia')]
#[IsGranted('ROLE_AGENCY')]
class AgencyController extends AbstractController
{
    /** 9. Scrivania: spazio licenza (9.1), avvisi e documenti da verificare (9.2), pratiche contrassegnate (9.3). */
    #[Route('', name: 'agency_index', methods: ['GET'])]
    public function agencyIndex(CompanyService $companyService, ManagerRegistry $registry): Response
    {
        $company = $companyService->getCurrentCompany();
        $em = $registry->getManager('slave');
        $user = $this->getUser();

        // 9.1 Spazio: quello occupato è la dimensione reale del DB dell'agenzia più i file.
        $usedMb = $company !== null ? $companyService->getStorageUsedMb((string) $company->getDbName()) : 0.0;
        $quotaMb = $company?->getStorageQuotaMb() ?? 0;

        // 9.2 / 9.3 Come nell'elenco pratiche, chi non è amministratore vede solo le sue.
        $onlyMine = $user instanceof StaffUser && !$user->isAdmin() ? $user : null;

        return $this->render('role/agency/index.html.twig', [
            'company' => $company,
            'usedMb' => $usedMb,
            'quotaMb' => $quotaMb,
            'usedPercent' => $quotaMb > 0 ? min(100, round($usedMb / $quotaMb * 100, 1)) : 0,
            'alerts' => $this->alerts($em, $onlyMine),
            // Documenti che il notaio ha rimandato all'agenzia ("da verificare agenzia")
            'toVerify' => $em->getRepository(PracticeDocument::class)->findToVerifyForAgency($onlyMine?->getId()),
            'markedPractices' => $this->markedPractices($em, $onlyMine),
        ]);
    }

    /**
     * 9.2.1 Avvisi delle pratiche, i più imminenti per primi.
     *
     * @return PracticeAlert[]
     */
    private function alerts(EntityManagerInterface $em, ?StaffUser $onlyFor): array
    {
        $qb = $em->getRepository(PracticeAlert::class)->createQueryBuilder('a')
            ->join('a.practice', 'p')->addSelect('p')
            ->andWhere('p.status <> :archived')->setParameter('archived', Practice::STATUS_ARCHIVED)
            // I suoi avvisi e quelli del notaio segnati "visibile agenzia".
            ->andWhere('a.byNotary = false OR a.visibleToAgency = true')
            ->orderBy('a.remindAt', 'ASC')
            ->setMaxResults(50);

        if ($onlyFor !== null) {
            $qb->join('p.staff', 'ps')->andWhere('ps.id = :me')->setParameter('me', $onlyFor->getId());
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * 9.3.1 Pratiche con un contrassegno, escluse le archiviate.
     *
     * @return Practice[]
     */
    private function markedPractices(EntityManagerInterface $em, ?StaffUser $onlyFor): array
    {
        $qb = $em->getRepository(Practice::class)->createQueryBuilder('p')
            ->join('p.mark', 'm')->addSelect('m')
            ->leftJoin('p.seller', 's')->addSelect('s')
            ->leftJoin('p.buyer', 'b')->addSelect('b')
            ->andWhere('p.status <> :archived')->setParameter('archived', Practice::STATUS_ARCHIVED)
            ->orderBy('m.value', 'ASC')
            ->addOrderBy('p.createdAt', 'DESC');

        if ($onlyFor !== null) {
            $qb->join('p.staff', 'ps')->andWhere('ps.id = :me')->setParameter('me', $onlyFor->getId());
        }

        return $qb->getQuery()->getResult();
    }
}
