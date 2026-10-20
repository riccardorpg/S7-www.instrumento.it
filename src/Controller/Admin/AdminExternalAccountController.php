<?php

namespace App\Controller\Admin;

use App\Entity\ExternalAccount;
use App\Form\ExternalAccountType;
use App\Repository\CompanyRepository;
use App\Repository\ExternalAccountRepository;
use App\Service\FilterService;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * 8. Gestione account esterni. Ogni account è collegato a un cliente-studio (Company).
 * Nuovo e modifica avvengono in modale sulla lista.
 */
#[Route('/amministratore/account-esterni')]
#[IsGranted('ROLE_ADMIN')]
class AdminExternalAccountController extends AbstractController
{
    /**
     * Elenco, con i form di inserimento e modifica nei modali (CONVENZIONI 6, schema di
     * tillomeditalia): i form si inviano a questa stessa pagina, che li salva e torna all'elenco;
     * se non sono validi la pagina si ripresenta con il modale aperto e i campi segnati.
     * La modifica porta l'id nella query (?edit=ID), messo nell'action del form dal JS della riga.
     */
    #[Route('/lista', name: 'admin_external_accounts', methods: ['GET', 'POST'])]
    public function adminExternalAccounts(ExternalAccountRepository $accounts, CompanyRepository $companies, PaginatorInterface $paginator, FormFactoryInterface $formFactory, Request $request): Response
    {
        $formNew = $formFactory->createNamed('external_account_new', ExternalAccountType::class, new ExternalAccount());
        $formNew->handleRequest($request);
        if ($formNew->isSubmitted() && $formNew->isValid()) {
            $account = $formNew->getData();
            $account->setEmail(mb_strtolower((string) $account->getEmail()));
            $accounts->add($account);
            $this->addFlash('notice_success', 'Account esterno "' . $account->getFullName() . '" creato.');

            return $this->redirectToRoute('admin_external_accounts');
        }

        $editId = $request->query->getInt('edit');
        $edited = $editId > 0 ? $accounts->find($editId) : null;
        $formEdit = $formFactory->createNamed('external_account_edit', ExternalAccountType::class, $edited ?? new ExternalAccount());
        if ($edited !== null) {
            $formEdit->handleRequest($request);
            if ($formEdit->isSubmitted() && $formEdit->isValid()) {
                $edited->setEmail(mb_strtolower((string) $edited->getEmail()));
                $accounts->add($edited);
                $this->addFlash('notice_success', 'Account esterno aggiornato.');

                return $this->redirectToRoute('admin_external_accounts');
            }
        }

        $filters = FilterService::externalAccountFilter($request);
        $records = $paginator->paginate($accounts->queryAllFiltered($filters), FilterService::page($request, 'admin_external_accounts'), 20);

        return $this->render('role/admin/external_account/list.html.twig', [
            'records' => $records,
            'filters' => $filters,
            'companies' => $companies->findBy([], ['name' => 'ASC']),
            'formNew' => $formNew->createView(),
            'formEdit' => $formEdit->createView(),
        ]);
    }

    /** Ricerca studi (Company) per il campo ricercabile "Studio notarile di riferimento". */
    #[Route('/api/cerca-studio', name: 'admin_external_account_company_search', methods: ['GET'])]
    public function adminExternalAccountCompanySearch(Request $request, CompanyRepository $companies): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        $qb = $companies->createQueryBuilder('c')->orderBy('c.name', 'ASC')->setMaxResults(15);
        if ($q !== '') {
            $qb->andWhere('c.name LIKE :q OR c.code LIKE :q')->setParameter('q', '%' . $q . '%');
        }

        $items = array_map(static fn ($c) => [
            'id' => $c->getId(),
            'label' => $c->getName() . ' (' . $c->getCode() . ')',
        ], $qb->getQuery()->getResult());

        return $this->json($items);
    }

    #[Route('/{id}/elimina', name: 'admin_external_account_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adminExternalAccountDelete(ExternalAccount $account, Request $request, ExternalAccountRepository $accounts): Response
    {
        if ($this->isCsrfTokenValid('delete', (string) $request->request->get('_csrf_token')) && $account->getCanDelete()) {
            $accounts->remove($account);
            $this->addFlash('notice_success', 'Account esterno eliminato.');
        }

        return $this->redirectToRoute('admin_external_accounts');
    }
}
