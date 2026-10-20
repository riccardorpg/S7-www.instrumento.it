<?php

namespace App\Controller\Agency;

use App\Controller\Agency\Trait\AgencyContextTrait;
use App\Entity\Customer;
use App\Entity\Practice;
use App\Form\CustomerType;
use App\Repository\CustomerRepository;
use App\Service\FilterService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * 11. Clienti dell'agenzia: anagrafica di chi compra o vende nelle pratiche.
 * Elenco (11.1) con il modale del nuovo cliente e scheda (11.2) con il modale di modifica:
 * i form (CustomerType) si inviano alla pagina che li mostra (CONVENZIONI 6).
 */
#[Route('/agenzia/clienti')]
#[IsGranted('ROLE_USER')]
#[IsGranted(new Expression("is_granted('view', 'customers')"), message: 'Non hai accesso all\'anagrafica clienti.')]
class AgencyCustomerController extends AbstractController
{
    use AgencyContextTrait;

    private const PER_PAGE = 20;

    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    /**
     * 11.1 Elenco dei clienti, con il form del nuovo cliente nel modale: salvato, si va alla
     * scheda; se non è valido la pagina si ripresenta con il modale aperto e i campi segnati.
     */
    #[Route('/lista', name: 'agency_customers', methods: ['GET', 'POST'])]
    public function agencyCustomers(Request $request, PaginatorInterface $paginator, FormFactoryInterface $formFactory): Response
    {
        $customers = $this->customers();

        $formNew = $formFactory->createNamed('customer_new', CustomerType::class, (new Customer())->setCompany($this->company()));
        if ($this->isGranted('edit', 'customers')) {
            $formNew->handleRequest($request);
            if ($formNew->isSubmitted() && $formNew->isValid()) {
                $customer = $this->normalize($formNew->getData());
                $customers->add($customer);
                $this->addFlash('notice_success', 'Cliente "' . $customer->getFullName() . '" creato.');

                return $this->redirectToRoute('agency_customer_sheet', ['id' => $customer->getId()], Response::HTTP_SEE_OTHER);
            }
        }

        $filters = FilterService::customerFilter($request);
        $records = $paginator->paginate($customers->queryAllFiltered($filters), FilterService::page($request, 'agency_customers'), self::PER_PAGE);

        return $this->render('role/agency/customer/list.html.twig', [
            'records' => $records,
            'filters' => $filters,
            'formNew' => $formNew->createView(),
        ]);
    }

    /**
     * 11.2 Scheda cliente: anagrafica, dati fiscali ed elenco pratiche, con il form di
     * modifica nel modale (stessa pagina in GET e POST).
     */
    #[Route('/scheda/{id}', name: 'agency_customer_sheet', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function agencyCustomerSheet(int $id, Request $request, FormFactoryInterface $formFactory): Response
    {
        $em = $this->em();
        $customer = $em->getRepository(Customer::class)->find($id);
        if ($customer === null) {
            throw $this->createNotFoundException('Cliente non trovato.');
        }

        $formEdit = $formFactory->createNamed('customer_edit', CustomerType::class, $customer);
        if ($this->isGranted('edit', 'customers')) {
            $formEdit->handleRequest($request);
            if ($formEdit->isSubmitted()) {
                if ($formEdit->isValid()) {
                    $this->customers()->add($this->normalize($customer));
                    $this->addFlash('notice_success', 'Cliente aggiornato.');

                    return $this->redirectToRoute('agency_customer_sheet', ['id' => $id], Response::HTTP_SEE_OTHER);
                }
                // La scheda mostra i dati salvati, non quelli rifiutati (che restano nel modale).
                $em->refresh($customer);
            }
        }

        // 11.2.3 Le pratiche in cui il cliente è acquirente o venditore.
        $practices = $em->getRepository(Practice::class)->createQueryBuilder('p')
            ->leftJoin('p.buyer', 'b')->addSelect('b')
            ->leftJoin('p.seller', 's')->addSelect('s')
            ->andWhere('p.buyer = :c OR p.seller = :c')
            ->setParameter('c', $customer)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('role/agency/customer/sheet.html.twig', [
            'customer' => $customer,
            'practices' => $practices,
            // 11.2.3 Voci del filtro stato a scelta multipla.
            'statuses' => Practice::STATUSES,
            'formEdit' => $formEdit->createView(),
        ]);
    }

    /**
     * 12.2.1 / 12.2.2 Nuovo cliente "al volo" dal modale della pratica: stesso form e stessi
     * controlli del nuovo cliente, ma risposta JSON, così la pratica in compilazione non si
     * perde e l'autocomplete può selezionarlo subito. Se non è valido restituisce gli errori
     * per campo ({campo: messaggio}), che il modale segna sui campi.
     */
    #[Route('/api/nuovo', name: 'agency_customer_quick_new', methods: ['POST'])]
    #[IsGranted(new Expression("is_granted('edit', 'customers')"))]
    public function agencyCustomerQuickNew(Request $request, FormFactoryInterface $formFactory): JsonResponse
    {
        $form = $this->quickForm($formFactory);
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            return $this->json(['ok' => false, 'error' => 'Dati del cliente mancanti: ricarica la pagina.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$form->isValid()) {
            $fields = [];
            $global = [];
            foreach ($form->getErrors(true) as $error) {
                $origin = $error->getOrigin();
                if ($origin !== null && $origin !== $form) {
                    $name = $origin->getName();
                    $fields[$name] = isset($fields[$name]) ? $fields[$name] . ' ' . $error->getMessage() : $error->getMessage();
                } else {
                    // Errori del form intero (es. token scaduto).
                    $global[] = $error->getMessage();
                }
            }

            return $this->json([
                'ok' => false,
                'error' => $global !== [] ? implode(' ', $global) : 'Controlla i campi evidenziati.',
                'errors' => $fields,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $customer = $this->normalize($form->getData());
        $this->customers()->add($customer);

        return $this->json([
            'ok' => true,
            'id' => $customer->getId(),
            // Stessa etichetta di agency_customer_search: la tendina e il campo coincidono.
            'label' => $customer->getFullName() . ($customer->getFiscalCode() ? ' (' . $customer->getFiscalCode() . ')' : ''),
        ]);
    }

    /**
     * Campi del modale "nuovo cliente al volo" (role/agency/practice/_customer_quick_new.html.twig),
     * che li include con render(controller(...)): così il modale non dipende dai controller
     * delle pratiche. Nessuna rotta: è solo un frammento.
     */
    public function agencyCustomerQuickForm(FormFactoryInterface $formFactory): Response
    {
        return $this->render('role/agency/customer/_quick_form_fields.html.twig', [
            'form' => $this->quickForm($formFactory)->createView(),
        ]);
    }

    // ===================== SUPPORTO =====================

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManager();

        return $em;
    }

    private function customers(): CustomerRepository
    {
        /** @var CustomerRepository $customers */
        $customers = $this->em()->getRepository(Customer::class);

        return $customers;
    }

    private function quickForm(FormFactoryInterface $formFactory): FormInterface
    {
        return $formFactory->createNamed('customer_quick', CustomerType::class, (new Customer())->setCompany($this->company()));
    }

    /** Normalizzazioni dopo la validazione: codice fiscale e SDI in maiuscolo, e-mail e PEC in minuscolo. */
    private function normalize(Customer $customer): Customer
    {
        $fiscalCode = $customer->getFiscalCode();
        $sdi = $customer->getSdi();
        $email = $customer->getEmail();
        $pec = $customer->getPec();

        return $customer
            ->setFiscalCode($fiscalCode !== null ? mb_strtoupper($fiscalCode) : null)
            ->setSdi($sdi !== null ? mb_strtoupper($sdi) : null)
            ->setEmail($email !== null ? mb_strtolower($email) : null)
            ->setPec($pec !== null ? mb_strtolower($pec) : null);
    }
}
