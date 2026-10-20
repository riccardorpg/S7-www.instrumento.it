<?php

namespace App\Controller\Admin;

use App\Repository\CompanyRepository;
use App\Repository\DemoRequestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * 6. Scrivania admin: allarmi in tab.
 */
#[Route('/amministratore')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    #[Route('', name: 'admin_index', methods: ['GET'])]
    public function adminIndex(DemoRequestRepository $demoRequests, CompanyRepository $companies): Response
    {
        return $this->render('role/admin/index.html.twig', [
            // 6.1.1 Nuove richieste in arrivo
            'newRequests' => $demoRequests->findPending(),
            // Archivio delle richieste scartate (recuperabili)
            'rejectedRequests' => $demoRequests->findRejected(),
            // 6.1.2 Demo in scadenza (entro 30 giorni)
            'expiringDemos' => $companies->findExpiring(true, 30),
            // 6.1.3 Licenze in scadenza (entro 30 giorni)
            'expiringLicenses' => $companies->findExpiring(false, 30),
        ]);
    }

}
