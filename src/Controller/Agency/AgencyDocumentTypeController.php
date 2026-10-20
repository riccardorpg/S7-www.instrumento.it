<?php

namespace App\Controller\Agency;

use App\Controller\Agency\Trait\AgencyContextTrait;
use App\Entity\DocumentType;
use App\Entity\PracticeDocument;
use App\Form\DocumentTypeType;
use App\Repository\DocumentTypeRepository;
use App\Service\FilterService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * 13.1 Tipologie documento dell'agenzia: il catalogo dei documenti che le pratiche richiedono.
 * Vive nel DB dell'agenzia (slave), quindi ogni agenzia ha il proprio.
 *
 * Nuovo e modifica sono form Symfony nei modali dell'elenco, inviati all'elenco stesso
 * (CONVENZIONI 6, la modifica porta l'id in ?edit=ID); l'eliminazione passa da un modale di conferma.
 * Permesso: sezione "configurations" (lettura per vedere, rw per modificare).
 */
#[Route('/agenzia/tipologie-documento')]
#[IsGranted('ROLE_USER')]
#[IsGranted(new Expression("is_granted('view', 'configurations')"), message: 'Non hai accesso alle tipologie documento.')]
class AgencyDocumentTypeController extends AbstractController
{
    use AgencyContextTrait;

    private const PER_PAGE = 20;

    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    /**
     * 13.1.1 Elenco delle tipologie, nell'ordine impostato col drag & drop, con i form
     * di nuovo (13.1.1.2) e modifica (13.1.4) nei modali.
     */
    #[Route('/lista', name: 'agency_document_types', methods: ['GET', 'POST'])]
    public function agencyDocumentTypes(Request $request, PaginatorInterface $paginator, FormFactoryInterface $formFactory): Response
    {
        // I form di nuovo e modifica scrivono: serve il permesso di modifica (rw), non basta la lettura.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('edit', 'configurations', 'Non hai il permesso di modificare le tipologie documento.');
        }

        $em = $this->em();
        /** @var DocumentTypeRepository $types */
        $types = $em->getRepository(DocumentType::class);

        // Lo stato non è nel form: le nuove tipologie nascono attive (default dell'entità) e sulla
        // modifica resta quello corrente, che si cambia solo da 13.1.2.
        $formNew = $formFactory->createNamed('document_type_new', DocumentTypeType::class, (new DocumentType())->setCompany($this->company()));
        $formNew->handleRequest($request);
        if ($formNew->isSubmitted() && $formNew->isValid()) {
            $type = $formNew->getData();
            // 13.1.1.1 Le nuove tipologie si accodano in fondo; l'ordine si cambia trascinando.
            $type->setPriority($types->nextPriority());
            $types->add($type);
            $this->addFlash('notice_success', 'Tipologia documento "' . $type->getValue() . '" creata.');

            return $this->backToList();
        }

        $editId = $request->query->getInt('edit');
        $edited = $editId > 0 ? $types->find($editId) : null;
        $formEdit = $formFactory->createNamed('document_type_edit', DocumentTypeType::class, $edited ?? (new DocumentType())->setCompany($this->company()));
        if ($edited !== null) {
            $formEdit->handleRequest($request);
            if ($formEdit->isSubmitted()) {
                if ($formEdit->isValid()) {
                    $types->add($edited);
                    $this->addFlash('notice_success', 'Tipologia documento aggiornata.');

                    return $this->backToList();
                }
                // L'elenco mostra i dati salvati, non quelli rifiutati (che restano nel modale).
                $em->refresh($edited);
            }
        }

        $filters = FilterService::documentTypeFilter($request);
        $page = FilterService::page($request, 'agency_document_types');
        $records = $paginator->paginate($types->queryAllFiltered($filters), $page, self::PER_PAGE);

        return $this->render('role/agency/document_type/list.html.twig', [
            'records' => $records,
            'filters' => $filters,
            // 13.1.3 Il drag & drop riscrive le priorità in base alla posizione in tabella:
            // ha senso solo se le righe sono nell'ordine di priorità e non filtrate, altrimenti
            // trascinare dentro un sottoinsieme produrrebbe priorità sbagliate.
            // Riordinare è una modifica: solo con il permesso rw.
            'sortable' => $this->isGranted('edit', 'configurations') && $filters['value'] === '' && in_array($filters['sortField'], ['', 't.priority'], true) && $filters['sortOrder'] !== 'desc',
            // Con la paginazione le priorità della pagina partono dall'offset, non da zero.
            'priorityOffset' => max(0, ($page - 1) * self::PER_PAGE),
            // 13.1.5 Una tipologia già usata in una pratica non è eliminabile.
            'usedTypeIds' => $this->usedDocumentTypeIds(),
            'formNew' => $formNew->createView(),
            'formEdit' => $formEdit->createView(),
        ]);
    }

    /** 13.1.2 Attiva/disattiva. */
    #[Route('/{id}/stato', name: 'agency_document_type_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'configurations')"), message: 'Non hai il permesso di modificare le tipologie documento.')]
    public function agencyDocumentTypeToggle(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('docTypeToggle', (string) $request->request->get('_csrf_token'))) {
            return $this->backToList();
        }

        $em = $this->em();
        $type = $em->getRepository(DocumentType::class)->find($id);
        if ($type === null) {
            throw $this->createNotFoundException('Tipologia documento non trovata.');
        }

        $type->setActive(!$type->isActive());
        $em->flush();
        $this->addFlash('notice_success', sprintf(
            'Tipologia documento "%s" %s.',
            $type->getValue(),
            $type->isActive() ? 'attivata' : 'disattivata'
        ));

        return $this->backToList();
    }

    /**
     * 13.1.3 Drag & drop: riceve gli id nel nuovo ordine e riscrive le priorità.
     * Stessa meccanica del riordino mansionari di DualMoto (fetch JSON, nessun reload).
     */
    #[Route('/ordina', name: 'agency_document_type_reorder', methods: ['POST'])]
    #[IsGranted(new Expression("is_granted('edit', 'configurations')"), message: 'Non hai il permesso di modificare le tipologie documento.')]
    public function agencyDocumentTypeReorder(Request $request): JsonResponse
    {
        $payload = $request->toArray();
        if (!$this->isCsrfTokenValid('docTypeReorder', (string) ($payload['_csrf_token'] ?? ''))) {
            return new JsonResponse(['ok' => false, 'error' => 'Token non valido.'], Response::HTTP_BAD_REQUEST);
        }

        $order = array_map('intval', $payload['order'] ?? []);
        if ($order === []) {
            return new JsonResponse(['ok' => false, 'error' => 'Ordine vuoto.'], Response::HTTP_BAD_REQUEST);
        }

        // Offset della pagina: le righe visibili occupano le priorità da $offset in poi,
        // così trascinare in pagina 2 non le rinumera a partire da zero.
        $offset = max(0, (int) ($payload['offset'] ?? 0));

        $em = $this->em();
        $positions = array_flip($order); // id => posizione nella pagina
        foreach ($em->getRepository(DocumentType::class)->findAll() as $type) {
            $id = (int) $type->getId();
            if (isset($positions[$id])) {
                $type->setPriority($offset + $positions[$id]);
            }
        }
        $em->flush();

        return new JsonResponse(['ok' => true]);
    }

    /** 13.1.5 Elimina, solo se la tipologia non è mai stata usata in una pratica. */
    #[Route('/{id}/elimina', name: 'agency_document_type_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'configurations')"), message: 'Non hai il permesso di modificare le tipologie documento.')]
    public function agencyDocumentTypeDelete(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('delete', (string) $request->request->get('_csrf_token'))) {
            return $this->backToList();
        }

        $em = $this->em();
        $type = $em->getRepository(DocumentType::class)->find($id);
        if ($type === null) {
            throw $this->createNotFoundException('Tipologia documento non trovata.');
        }

        if (in_array((int) $type->getId(), $this->usedDocumentTypeIds(), true)) {
            $this->addFlash('notice_danger', 'La tipologia documento "' . $type->getValue() . '" è già stata usata in una pratica: puoi solo disattivarla.');

            return $this->backToList();
        }

        $value = $type->getValue();
        $em->remove($type);
        $em->flush();
        $this->addFlash('notice_success', 'Tipologia documento "' . $value . '" eliminata.');

        return $this->backToList();
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManager();

        return $em;
    }

    private function backToList(): RedirectResponse
    {
        return $this->redirectToRoute('agency_document_types', [], Response::HTTP_SEE_OTHER);
    }

    /** Id delle tipologie già usate da almeno una riga documentale di pratica. */
    private function usedDocumentTypeIds(): array
    {
        $rows = $this->em()->createQueryBuilder()
            ->select('IDENTITY(pd.documentType) AS tid')
            ->from(PracticeDocument::class, 'pd')
            ->andWhere('pd.documentType IS NOT NULL')
            ->distinct()
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $r) => (int) $r['tid'], $rows);
    }
}
