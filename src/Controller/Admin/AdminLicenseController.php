<?php

namespace App\Controller\Admin;

use App\Entity\Master\License;
use App\Form\LicenseFreeDeleteType;
use App\Form\LicenseNewType;
use App\Form\Model\LicenseFreeDelete;
use App\Form\Model\LicenseNew;
use App\Repository\Master\LicenseRepository;
use App\Repository\Master\LicenseTypeRepository;
use App\Service\FilterService;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestione licenze (come eaml.software): l'admin crea le licenze, che restano libere
 * finché non vengono assegnate a un cliente dalla sua scheda (AdminClientController), dove
 * si cambiano e si rinnovano con una data di attivazione (License::start).
 * Qui: le libere si eliminano (per tipo e quantità); quelle in uso si attivano/disattivano e si
 * revocano (tornano libere). Una licenza scaduta resta al cliente, non valida, finché non viene rinnovata.
 */
#[Route('/amministratore/licenze')]
#[IsGranted('ROLE_ADMIN')]
class AdminLicenseController extends AbstractController
{
    /**
     * Elenco a due schede: "occupate" (in uso: una riga per licenza, filtrabile e paginata) e
     * "libere" (una riga per tipo, con la quantità). I form "Crea licenze" ed "Elimina licenze" stanno
     * nei modali e si inviano a questa stessa pagina (CONVENZIONI 6): se non sono validi la pagina si
     * ripresenta con il modale aperto e i campi segnati.
     */
    #[Route('/lista', name: 'admin_licenses', methods: ['GET', 'POST'])]
    public function adminLicenses(LicenseRepository $licenses, LicenseTypeRepository $licenseTypes, PaginatorInterface $paginator, FormFactoryInterface $formFactory, Request $request): Response
    {
        // Crea una o più licenze libere dello stesso tipo: il loro anno parte dall'assegnazione.
        $formNew = $formFactory->createNamed('license_new', LicenseNewType::class, new LicenseNew());
        $formNew->handleRequest($request);
        if ($formNew->isSubmitted() && $formNew->isValid()) {
            /** @var LicenseNew $data */
            $data = $formNew->getData();
            $type = $data->getType();
            $quantity = (int) $data->getQuantity();
            for ($i = 1; $i <= $quantity; ++$i) {
                // Un solo flush, sull'ultima.
                $licenses->add((new License())->setType($type), $i === $quantity);
            }

            $this->addFlash('notice_success', $quantity === 1
                ? 'Licenza ' . $type->getLabel() . ' creata: la trovi fra le licenze libere.'
                : $quantity . ' licenze ' . $type->getLabel() . ' create: le trovi fra le licenze libere.');

            return $this->redirectToFreeTab($request);
        }

        // Elimina N licenze libere di un tipo (le ultime create): non più di quante ne restano libere.
        $freeCounts = $licenses->countFreeByType();
        $formDelete = $formFactory->createNamed('license_free_delete', LicenseFreeDeleteType::class, new LicenseFreeDelete());
        $formDelete->handleRequest($request);
        if ($formDelete->isSubmitted() && $formDelete->isValid()) {
            /** @var LicenseFreeDelete $data */
            $data = $formDelete->getData();
            $type = $data->getType();
            $quantity = (int) $data->getQuantity();
            $available = $freeCounts[$type->getId()] ?? 0;
            if ($quantity > $available) {
                $formDelete->get('quantity')->addError(new FormError($available === 0
                    ? 'Non ci sono licenze ' . $type->getLabel() . ' libere'
                    : 'Al massimo ' . $available));
            } else {
                $toDelete = $licenses->findFreeByType($type, $quantity);
                foreach ($toDelete as $i => $license) {
                    // Un solo flush, sull'ultima.
                    $licenses->remove($license, $i === array_key_last($toDelete));
                }

                $this->addFlash('notice_success', $quantity === 1
                    ? 'Licenza ' . $type->getLabel() . ' libera eliminata.'
                    : $quantity . ' licenze ' . $type->getLabel() . ' libere eliminate.');

                return $this->redirectToFreeTab($request);
            }
        }

        $filters = FilterService::licenseFilter($request);
        $types = $licenseTypes->findAllOrdered();

        // Scheda "libere": una riga per tipo con almeno una licenza libera, in ordine di spazio.
        $freeGroups = [];
        foreach ($types as $type) {
            if (($freeCounts[$type->getId()] ?? 0) > 0) {
                $freeGroups[] = ['type' => $type, 'quantity' => $freeCounts[$type->getId()]];
            }
        }

        return $this->render('role/admin/license/list.html.twig', [
            'records' => $paginator->paginate($licenses->queryAllFiltered($filters), FilterService::page($request, 'admin_licenses'), 20),
            'freeGroups' => $freeGroups,
            'filters' => $filters,
            'counts' => $licenses->countByTab(),
            'licenseTypes' => $types,
            'formNew' => $formNew->createView(),
            'formDelete' => $formDelete->createView(),
        ]);
    }

    /** "Cambia stato" (come in eaml): attiva o disattiva una licenza in uso; disattivata non è valida. */
    #[Route('/{id}/stato', name: 'admin_license_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adminLicenseToggle(License $license, Request $request, LicenseRepository $licenses): Response
    {
        if (!$license->isFree() && $this->isCsrfTokenValid('license_' . $license->getId(), (string) $request->request->get('_csrf_token'))) {
            $license->setAdminActive(!$license->isAdminActive());
            $licenses->add($license);
            $this->addFlash('notice_success', 'Licenza di ' . $license->getCompany()->getName() . ($license->isAdminActive() ? ' attivata.' : ' disattivata.'));
        }

        return $this->redirectToRoute('admin_licenses');
    }

    /** Revoca dall'elenco (come dalla scheda cliente): il cliente resta senza licenza, la licenza torna libera. */
    #[Route('/{id}/revoca', name: 'admin_license_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adminLicenseRevoke(License $license, Request $request, LicenseRepository $licenses): Response
    {
        $company = $license->getCompany();
        if ($company !== null && $this->isCsrfTokenValid('license_' . $license->getId(), (string) $request->request->get('_csrf_token'))) {
            $company->setLicense(null);
            $licenses->add($license);
            $this->addFlash('notice_success', 'Licenza revocata a ' . $company->getName() . ': ora è di nuovo libera.');
        }

        return $this->redirectToRoute('admin_licenses');
    }

    /**
     * Torna all'elenco sulla scheda "Licenze libere": la scheda attiva sta in sessione
     * (chiave "{rotta}_tab", come components/js/tab_session_js), non nell'URL.
     */
    private function redirectToFreeTab(Request $request): Response
    {
        $request->getSession()->set('admin_licenses_tab', 'libere');

        return $this->redirectToRoute('admin_licenses');
    }
}
