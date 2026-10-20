<?php

namespace App\Controller\Agency;

use App\Controller\Agency\Trait\AgencyContextTrait;
use App\Entity\Company;
use App\Entity\Practice;
use App\Entity\PracticeAlert;
use App\Entity\PracticeDocument;
use App\Entity\User as StaffUser;
use App\Service\CompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/agenzia')]
#[IsGranted('ROLE_USER')]
class AgencyController extends AbstractController
{
    use AgencyContextTrait;

    /** 9. Scrivania: spazio licenza (9.1), avvisi e documenti da verificare (9.2), pratiche contrassegnate (9.3). */
    #[Route('', name: 'agency_index', methods: ['GET'])]
    public function agencyIndex(CompanyService $companyService, EntityManagerInterface $em): Response
    {
        $company = $this->company();
        $user = $this->getUser();

        // 9.1 Spazio: quello occupato dai file caricati nelle pratiche dell'agenzia.
        $usedMb = $companyService->getStorageUsedMb($company);
        $quotaMb = $company->getStorageQuotaMb() ?? 0;

        // 9.2 / 9.3 Come nell'elenco pratiche, chi non è amministratore vede solo le sue.
        $onlyMine = $user instanceof StaffUser && !$user->isAdmin() ? $user : null;

        return $this->render('role/agency/index.html.twig', [
            'company' => $company,
            'usedMb' => $usedMb,
            'quotaMb' => $quotaMb,
            'usedPercent' => $quotaMb > 0 ? min(100, round($usedMb / $quotaMb * 100, 1)) : 0,
            'alerts' => $this->alerts($em, $company, $onlyMine),
            // Documenti che il notaio ha rimandato all'agenzia ("da verificare agenzia")
            'toVerify' => $em->getRepository(PracticeDocument::class)->findToVerifyForAgency($company, $onlyMine?->getId()),
            'markedPractices' => $this->markedPractices($em, $company, $onlyMine),
        ]);
    }

    /**
     * 9.2.1 Avvisi delle pratiche, i più imminenti per primi.
     *
     * @return PracticeAlert[]
     */
    private function alerts(EntityManagerInterface $em, Company $company, ?StaffUser $onlyFor): array
    {
        $qb = $em->getRepository(PracticeAlert::class)->createQueryBuilder('a')
            ->join('a.practice', 'p')->addSelect('p')
            ->andWhere('p.company = :company')->setParameter('company', $company)
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
    private function markedPractices(EntityManagerInterface $em, Company $company, ?StaffUser $onlyFor): array
    {
        $qb = $em->getRepository(Practice::class)->createQueryBuilder('p')
            ->andWhere('p.company = :company')->setParameter('company', $company)
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
