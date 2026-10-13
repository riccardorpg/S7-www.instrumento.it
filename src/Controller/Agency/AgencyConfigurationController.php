<?php

namespace App\Controller\Agency;

use App\Entity\Slave\DocumentType;
use App\Entity\Slave\PracticeDocument;
use App\Entity\Slave\PracticeMark;
use App\Entity\Slave\PracticeTag;
use App\Form\DocumentTypeType;
use App\Form\PracticeMarkType;
use App\Form\PracticeTagType;
use App\Repository\Slave\DocumentTypeRepository;
use App\Repository\Slave\PracticeMarkRepository;
use App\Repository\Slave\PracticeTagRepository;
use App\Service\FilterService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * 13. Configurazioni dell'agenzia: tipi di documento (13.1), contrassegni (13.2)
 * e tag (13.3) delle pratiche. Tutto vive nel DB dell'agenzia (slave), quindi ogni
 * agenzia ha il proprio catalogo.
 *
 * Una pagina per configurazione, raggiungibili dal sottomenu "Configurazioni".
 * Nuovo e modifica sono form Symfony nei modali dell'elenco, inviati all'elenco stesso
 * (CONVENZIONI 6, la modifica porta l'id in ?edit=ID); ogni eliminazione passa da un
 * modale di conferma.
 */
#[Route('/agenzia/configurazioni')]
#[IsGranted('ROLE_AGENCY')]
#[IsGranted(new Expression("is_granted('view', 'configurations')"), message: 'Non hai accesso alle configurazioni.')]
class AgencyConfigurationController extends AbstractController
{
    private const PER_PAGE = 20;

    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    // ===================== 13.1 TIPI DI DOCUMENTO =====================

    /**
     * 13.1.1 Elenco dei tipi di documento, nell'ordine impostato col drag & drop, con i form
     * di nuovo (13.1.1.2) e modifica (13.1.4) nei modali.
     */
    #[Route('/tipi-documento', name: 'agency_configuration_document_types', methods: ['GET', 'POST'])]
    public function agencyConfigurationDocumentTypes(Request $request, PaginatorInterface $paginator, FormFactoryInterface $formFactory): Response
    {
        // I form di nuovo e modifica scrivono: serve il permesso di modifica (rw), non basta la lettura.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('edit', 'configurations', 'Non hai il permesso di modificare le configurazioni.');
        }

        $em = $this->slave();
        /** @var DocumentTypeRepository $types */
        $types = $em->getRepository(DocumentType::class);

        // Lo stato non è nel form: i nuovi tipi nascono attivi (default dell'entità) e sulla
        // modifica resta quello corrente, che si cambia solo da 13.1.2.
        $formNew = $formFactory->createNamed('document_type_new', DocumentTypeType::class, new DocumentType());
        $formNew->handleRequest($request);
        if ($formNew->isSubmitted() && $formNew->isValid()) {
            $type = $formNew->getData();
            // 13.1.1.1 I nuovi tipi si accodano in fondo; l'ordine si cambia trascinando.
            $type->setPriority($types->nextPriority());
            $types->add($type);
            $this->addFlash('notice_success', 'Tipo di documento "' . $type->getValue() . '" creato.');

            return $this->backTo('agency_configuration_document_types');
        }

        $editId = $request->query->getInt('edit');
        $edited = $editId > 0 ? $types->find($editId) : null;
        $formEdit = $formFactory->createNamed('document_type_edit', DocumentTypeType::class, $edited ?? new DocumentType());
        if ($edited !== null) {
            $formEdit->handleRequest($request);
            if ($formEdit->isSubmitted()) {
                if ($formEdit->isValid()) {
                    $types->add($edited);
                    $this->addFlash('notice_success', 'Tipo di documento aggiornato.');

                    return $this->backTo('agency_configuration_document_types');
                }
                // L'elenco mostra i dati salvati, non quelli rifiutati (che restano nel modale).
                $em->refresh($edited);
            }
        }

        $filters = FilterService::documentTypeFilter($request);
        $page = FilterService::page($request, 'agency_configuration_document_types');
        $records = $paginator->paginate($types->queryAllFiltered($filters), $page, self::PER_PAGE);

        return $this->render('role/agency/configuration/document_type/list.html.twig', [
            'records' => $records,
            'filters' => $filters,
            // 13.1.3 Il drag & drop riscrive le priorità in base alla posizione in tabella:
            // ha senso solo se le righe sono nell'ordine di priorità e non filtrate, altrimenti
            // trascinare dentro un sottoinsieme produrrebbe priorità sbagliate.
            // Riordinare è una modifica: solo con il permesso rw.
            'sortable' => $this->isGranted('edit', 'configurations') && $filters['value'] === '' && in_array($filters['sortField'], ['', 't.priority'], true) && $filters['sortOrder'] !== 'desc',
            // Con la paginazione le priorità della pagina partono dall'offset, non da zero.
            'priorityOffset' => max(0, ($page - 1) * self::PER_PAGE),
            // 13.1.5 Un tipo già usato in una pratica non è eliminabile.
            'usedTypeIds' => $this->usedDocumentTypeIds(),
            'formNew' => $formNew->createView(),
            'formEdit' => $formEdit->createView(),
        ]);
    }

    /** 13.1.2 Attiva/disattiva. */
    #[Route('/tipi-documento/{id}/stato', name: 'agency_configuration_document_type_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'configurations')"), message: 'Non hai il permesso di modificare le configurazioni.')]
    public function agencyConfigurationDocumentTypeToggle(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('docTypeToggle', (string) $request->request->get('_csrf_token'))) {
            return $this->backTo('agency_configuration_document_types');
        }

        $em = $this->slave();
        $type = $em->getRepository(DocumentType::class)->find($id);
        if ($type === null) {
            throw $this->createNotFoundException('Tipo di documento non trovato.');
        }

        $type->setActive(!$type->isActive());
        $em->flush();
        $this->addFlash('notice_success', sprintf(
            'Tipo di documento "%s" %s.',
            $type->getValue(),
            $type->isActive() ? 'attivato' : 'disattivato'
        ));

        return $this->backTo('agency_configuration_document_types');
    }

    /**
     * 13.1.3 Drag & drop: riceve gli id nel nuovo ordine e riscrive le priorità.
     * Stessa meccanica del riordino mansionari di DualMoto (fetch JSON, nessun reload).
     */
    #[Route('/tipi-documento/ordina', name: 'agency_configuration_document_type_reorder', methods: ['POST'])]
    #[IsGranted(new Expression("is_granted('edit', 'configurations')"), message: 'Non hai il permesso di modificare le configurazioni.')]
    public function agencyConfigurationDocumentTypeReorder(Request $request): JsonResponse
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

        $em = $this->slave();
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

    /** 13.1.5 Elimina, solo se il tipo non è mai stato usato in una pratica. */
    #[Route('/tipi-documento/{id}/elimina', name: 'agency_configuration_document_type_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'configurations')"), message: 'Non hai il permesso di modificare le configurazioni.')]
    public function agencyConfigurationDocumentTypeDelete(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('delete', (string) $request->request->get('_csrf_token'))) {
            return $this->backTo('agency_configuration_document_types');
        }

        $em = $this->slave();
        $type = $em->getRepository(DocumentType::class)->find($id);
        if ($type === null) {
            throw $this->createNotFoundException('Tipo di documento non trovato.');
        }

        if (in_array((int) $type->getId(), $this->usedDocumentTypeIds(), true)) {
            $this->addFlash('notice_danger', 'Il tipo di documento "' . $type->getValue() . '" è già stato usato in una pratica: puoi solo disattivarlo.');

            return $this->backTo('agency_configuration_document_types');
        }

        $value = $type->getValue();
        $em->remove($type);
        $em->flush();
        $this->addFlash('notice_success', 'Tipo di documento "' . $value . '" eliminato.');

        return $this->backTo('agency_configuration_document_types');
    }

    // ===================== 13.2 CONTRASSEGNI PRATICHE =====================

    /** 13.2.1 Elenco dei contrassegni inseriti, con i form di nuovo (13.2.2) e modifica (13.2.1.1) nei modali. */
    #[Route('/contrassegni', name: 'agency_configuration_marks', methods: ['GET', 'POST'])]
    public function agencyConfigurationMarks(Request $request, PaginatorInterface $paginator, FormFactoryInterface $formFactory): Response
    {
        // I form di nuovo e modifica scrivono: serve il permesso di modifica (rw), non basta la lettura.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('edit', 'configurations', 'Non hai il permesso di modificare le configurazioni.');
        }

        /** @var PracticeMarkRepository $marks */
        $marks = $this->slave()->getRepository(PracticeMark::class);
        $forms = $this->colorValueForms($request, $formFactory, $marks, PracticeMarkType::class, 'practice_mark', new PracticeMark(), 'Contrassegno', 'agency_configuration_marks');
        if ($forms instanceof Response) {
            return $forms;
        }

        $filters = FilterService::colorValueFilter($request, 'agency_configuration_marks');

        return $this->render('role/agency/configuration/mark/list.html.twig', [
            'records' => $paginator->paginate($marks->queryAllFiltered($filters), FilterService::page($request, 'agency_configuration_marks'), self::PER_PAGE),
            'filters' => $filters,
            'formNew' => $forms[0]->createView(),
            'formEdit' => $forms[1]->createView(),
        ]);
    }

    /** 13.2.1.2 Elimina. */
    #[Route('/contrassegni/{id}/elimina', name: 'agency_configuration_mark_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'configurations')"), message: 'Non hai il permesso di modificare le configurazioni.')]
    public function agencyConfigurationMarkDelete(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('delete', (string) $request->request->get('_csrf_token'))) {
            return $this->backTo('agency_configuration_marks');
        }

        $em = $this->slave();
        $mark = $em->getRepository(PracticeMark::class)->find($id);
        if ($mark === null) {
            throw $this->createNotFoundException('Contrassegno non trovato.');
        }

        $value = $mark->getValue();
        $em->remove($mark);
        $em->flush();
        $this->addFlash('notice_success', 'Contrassegno "' . $value . '" eliminato.');

        return $this->backTo('agency_configuration_marks');
    }

    // ===================== 13.3 TAG PRATICHE =====================

    /** 13.3.1 Elenco dei tag inseriti, con i form di nuovo (13.3.2) e modifica (13.3.1.1) nei modali. */
    #[Route('/tag', name: 'agency_configuration_tags', methods: ['GET', 'POST'])]
    public function agencyConfigurationTags(Request $request, PaginatorInterface $paginator, FormFactoryInterface $formFactory): Response
    {
        // I form di nuovo e modifica scrivono: serve il permesso di modifica (rw), non basta la lettura.
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('edit', 'configurations', 'Non hai il permesso di modificare le configurazioni.');
        }

        /** @var PracticeTagRepository $tags */
        $tags = $this->slave()->getRepository(PracticeTag::class);
        $forms = $this->colorValueForms($request, $formFactory, $tags, PracticeTagType::class, 'practice_tag', new PracticeTag(), 'Tag', 'agency_configuration_tags');
        if ($forms instanceof Response) {
            return $forms;
        }

        $filters = FilterService::colorValueFilter($request, 'agency_configuration_tags');

        return $this->render('role/agency/configuration/tag/list.html.twig', [
            'records' => $paginator->paginate($tags->queryAllFiltered($filters), FilterService::page($request, 'agency_configuration_tags'), self::PER_PAGE),
            'filters' => $filters,
            'formNew' => $forms[0]->createView(),
            'formEdit' => $forms[1]->createView(),
        ]);
    }

    /** 13.3.1.2 Elimina. */
    #[Route('/tag/{id}/elimina', name: 'agency_configuration_tag_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'configurations')"), message: 'Non hai il permesso di modificare le configurazioni.')]
    public function agencyConfigurationTagDelete(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('delete', (string) $request->request->get('_csrf_token'))) {
            return $this->backTo('agency_configuration_tags');
        }

        $em = $this->slave();
        $tag = $em->getRepository(PracticeTag::class)->find($id);
        if ($tag === null) {
            throw $this->createNotFoundException('Tag non trovato.');
        }

        $value = $tag->getValue();
        $em->remove($tag);
        $em->flush();
        $this->addFlash('notice_success', 'Tag "' . $value . '" eliminato.');

        return $this->backTo('agency_configuration_tags');
    }

    // ===================== SUPPORTO =====================

    private function slave(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManager('slave');

        return $em;
    }

    /** Torna all'elenco della configurazione su cui si stava lavorando. */
    private function backTo(string $route): RedirectResponse
    {
        return $this->redirectToRoute($route, [], Response::HTTP_SEE_OTHER);
    }

    /** Id dei tipi di documento già usati da almeno una riga documentale di pratica. */
    private function usedDocumentTypeIds(): array
    {
        $rows = $this->slave()->createQueryBuilder()
            ->select('IDENTITY(pd.documentType) AS tid')
            ->from(PracticeDocument::class, 'pd')
            ->andWhere('pd.documentType IS NOT NULL')
            ->distinct()
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $r) => (int) $r['tid'], $rows);
    }

    /**
     * Form di nuovo e modifica di contrassegni (13.2) e tag (13.3), che hanno la stessa gestione:
     * salva e torna all'elenco (Response), oppure restituisce i due form da mostrare nei modali.
     * Il colore si salva in minuscolo, come prima della conversione a form.
     *
     * @param class-string $typeClass PracticeMarkType | PracticeTagType
     *
     * @return Response|array{0: FormInterface, 1: FormInterface}
     */
    private function colorValueForms(Request $request, FormFactoryInterface $formFactory, PracticeMarkRepository|PracticeTagRepository $repo, string $typeClass, string $formName, PracticeMark|PracticeTag $empty, string $label, string $route): Response|array
    {
        $formNew = $formFactory->createNamed($formName . '_new', $typeClass, clone $empty);
        $formNew->handleRequest($request);
        if ($formNew->isSubmitted() && $formNew->isValid()) {
            $entity = $formNew->getData();
            $entity->setColor(strtolower($entity->getColor()));
            $repo->add($entity);
            $this->addFlash('notice_success', $label . ' "' . $entity->getValue() . '" creato.');

            return $this->backTo($route);
        }

        $editId = $request->query->getInt('edit');
        $edited = $editId > 0 ? $repo->find($editId) : null;
        $formEdit = $formFactory->createNamed($formName . '_edit', $typeClass, $edited ?? clone $empty);
        if ($edited !== null) {
            $formEdit->handleRequest($request);
            if ($formEdit->isSubmitted()) {
                if ($formEdit->isValid()) {
                    $edited->setColor(strtolower($edited->getColor()));
                    $repo->add($edited);
                    $this->addFlash('notice_success', $label . ' aggiornato.');

                    return $this->backTo($route);
                }
                // L'elenco mostra i dati salvati, non quelli rifiutati (che restano nel modale).
                $this->slave()->refresh($edited);
            }
        }

        return [$formNew, $formEdit];
    }
}
