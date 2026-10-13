<?php

namespace App\Controller\Agency;

use App\Entity\Master\City;
use App\Entity\Master\Company;
use App\Entity\Master\Zip;
use App\Form\CompanyLogoType;
use App\Form\CompanyProfileType;
use App\Form\CompanyTaxType;
use App\Form\Model\CompanyLogo;
use App\Repository\Master\CompanyRepository;
use App\Service\CompanyLogoService;
use App\Service\CompanyService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * 7.1.9 Anagrafica della propria agenzia, vista dall'agenzia stessa.
 *
 * I dati vivono su Company, nel DB master: qui l'agenzia può correggere i propri dati
 * anagrafici e fiscali e caricare il logo. Restano all'amministrazione della
 * piattaforma le cose che non la riguardano — codice cliente, database, licenza,
 * quota di spazio e stato — che infatti nemmeno arrivano nei form.
 */
#[Route('/agenzia/anagrafica')]
#[IsGranted('ROLE_AGENCY')]
#[IsGranted(new Expression("is_granted('view', 'company')"), message: 'Non hai accesso all\'anagrafica dell\'agenzia.')]
class AgencyCompanyController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly CompanyService $companyService,
        private readonly CompanyLogoService $logos,
    ) {
    }

    /**
     * 7.1.9 Scheda dell'agenzia, con i form dei modali "Dati anagrafici", "Dati fiscali" e "Logo"
     * (CONVENZIONI 6): si inviano a questa stessa pagina, che salva e torna alla scheda; se non
     * sono validi la scheda si ripresenta con il modale aperto e i campi segnati.
     * Vedere la scheda richiede il permesso di lettura, inviare un form quello di modifica.
     */
    #[Route('', name: 'agency_company_sheet', methods: ['GET', 'POST'])]
    public function agencyCompanySheet(Request $request, FormFactoryInterface $formFactory, CompanyRepository $companies): Response
    {
        $company = $this->requireCompany();
        $canEdit = $this->isGranted('edit', 'company');
        if ($request->isMethod('POST') && !$canEdit) {
            throw $this->createAccessDeniedException('Non puoi modificare l\'anagrafica dell\'agenzia.');
        }

        // 7.1.9.1 Dati anagrafici: denominazione, tipo e sede.
        $formProfile = $formFactory->createNamed('company_profile', CompanyProfileType::class, $company);
        // 7.1.9.1 Dati fiscali: P.IVA, codice fiscale, SDI e PEC.
        $formTax = $formFactory->createNamed('company_tax', CompanyTaxType::class, $company);
        // 7.1.9.8 Logo (carica o sostituisce).
        $formLogo = $formFactory->createNamed('company_logo', CompanyLogoType::class, new CompanyLogo());

        [$city, $zip] = [$company->getCity(), $company->getZip()];
        if ($canEdit) {
            $formProfile->handleRequest($request);
            if ($formProfile->isSubmitted()) {
                // Città e CAP dal picker (fuori dal form).
                [$city, $zip] = $this->resolveGeo($request);
                if ($formProfile->isValid()) {
                    $company->setCity($city)->setZip($zip);
                    $companies->add($company);
                    $this->addFlash('notice_success', 'Dati anagrafici aggiornati.');

                    return $this->redirectToRoute('agency_company_sheet', [], Response::HTTP_SEE_OTHER);
                }
                // Non valido: la scheda torna ai dati salvati, il modale tiene quelli inviati.
                $this->master()->refresh($company);
            }

            $formTax->handleRequest($request);
            if ($formTax->isSubmitted()) {
                if ($formTax->isValid()) {
                    $company->setTaxCode($company->getTaxCode() !== null ? mb_strtoupper($company->getTaxCode()) : null)
                        ->setSdi($company->getSdi() !== null ? mb_strtoupper($company->getSdi()) : null);
                    $companies->add($company);
                    $this->addFlash('notice_success', 'Dati fiscali aggiornati.');

                    return $this->redirectToRoute('agency_company_sheet', [], Response::HTTP_SEE_OTHER);
                }
                $this->master()->refresh($company);
            }

            $formLogo->handleRequest($request);
            if ($formLogo->isSubmitted() && $formLogo->isValid()) {
                /** @var CompanyLogo $logo */
                $logo = $formLogo->getData();
                try {
                    $company->setLogo($this->logos->store($company, $logo->getFile()));
                    $companies->add($company);
                    $this->addFlash('notice_success', 'Logo aggiornato.');
                } catch (\Throwable $e) {
                    $this->addFlash('notice_danger', 'Caricamento del logo non riuscito: ' . $e->getMessage());
                }

                return $this->redirectToRoute('agency_company_sheet', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('role/agency/company/sheet.html.twig', [
            'company' => $company,
            'storageUsedMb' => $this->companyService->getStorageUsedMb((string) $company->getDbName()),
            'formProfile' => $formProfile->createView(),
            'formTax' => $formTax->createView(),
            'formLogo' => $formLogo->createView(),
            // Il picker città del modale riparte da ciò che era stato scelto se il form torna indietro.
            'profileCity' => $city,
            'profileZip' => $zip,
        ]);
    }

    /** 7.1.9.8 Rimuove il logo. */
    #[Route('/logo/elimina', name: 'agency_company_logo_delete', methods: ['POST'])]
    #[IsGranted(new Expression("is_granted('edit', 'company')"))]
    public function agencyCompanyLogoDelete(Request $request): RedirectResponse
    {
        $company = $this->requireCompany();
        if ($this->isCsrfTokenValid('delete', (string) $request->request->get('_csrf_token'))) {
            $this->logos->delete($company);
            $company->setLogo(null);
            $this->master()->flush();
            $this->addFlash('notice_success', 'Logo rimosso.');
        }

        return $this->redirectToRoute('agency_company_sheet', [], Response::HTTP_SEE_OTHER);
    }

    /** Il file del logo: sta fuori dalla webroot, quindi passa da qui. */
    #[Route('/logo/file', name: 'agency_company_logo_file', methods: ['GET'])]
    public function agencyCompanyLogoFile(): Response
    {
        $path = $this->logos->absolutePath($this->requireCompany());
        if ($path === null) {
            throw $this->createNotFoundException('Logo non presente.');
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition('inline', basename($path));

        return $response;
    }

    // ===================== SUPPORTO =====================

    private function master(): \Doctrine\ORM\EntityManagerInterface
    {
        /** @var \Doctrine\ORM\EntityManagerInterface $em */
        $em = $this->registry->getManager('master');

        return $em;
    }

    /** L'agenzia del contesto corrente: senza di essa non c'è anagrafica da mostrare. */
    private function requireCompany(): Company
    {
        $company = $this->companyService->getCurrentCompany();
        if ($company === null) {
            throw $this->createAccessDeniedException('Contesto agenzia non disponibile.');
        }

        return $company;
    }

    /**
     * Città e CAP dal catalogo geografico del master (picker Città/CAP).
     *
     * @return array{0: City|null, 1: Zip|null}
     */
    private function resolveGeo(Request $request): array
    {
        $master = $this->master();
        $cityId = (int) $request->request->get('city_id');
        $zipId = (int) $request->request->get('zip_id');

        return [
            $cityId > 0 ? $master->getRepository(City::class)->find($cityId) : null,
            $zipId > 0 ? $master->getRepository(Zip::class)->find($zipId) : null,
        ];
    }
}
