<?php

namespace App\Controller\Agency;

use App\Controller\Trait\ParsesDatesTrait;
use App\Entity\Master\City;
use App\Entity\Master\User as NotaryUser;
use App\Entity\Master\Zip;
use App\Entity\Slave\Customer;
use App\Entity\Slave\Document;
use App\Entity\Slave\Practice;
use App\Entity\Slave\PracticeAlert;
use App\Entity\Slave\PracticeDocument;
use App\Entity\Slave\PracticeMark;
use App\Entity\Slave\PracticeTag;
use App\Entity\Slave\User as StaffUser;
use App\Service\CompanyService;
use App\Service\DocumentStorage;
use App\Service\PracticeDocumentSync;
use App\Service\PracticeNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * 12. Pratiche dell'agenzia: elenco (12.1), inserimento (12.2) e scheda (12.3).
 * Vivono nel DB dell'agenzia (slave); il notaio le lavora dalla sua area (17).
 */
#[Route('/agenzia/pratiche')]
#[IsGranted('ROLE_AGENCY')]
#[IsGranted(new Expression("is_granted('view', 'practices')"), message: 'Non hai accesso alle pratiche.')]
class PracticeController extends AbstractController
{
    use ParsesDatesTrait;

    private const PER_PAGE = 20;

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly PracticeDocumentSync $documentSync,
        private readonly CompanyService $companyService,
    ) {
    }

    /** 12.1 Elenco pratiche. */
    #[Route('', name: 'agency_practices', methods: ['GET'])]
    public function index(Request $request, PaginatorInterface $paginator): Response
    {
        $filters = [
            // Stato: elenco di valori separati da virgola (filtro a scelta multipla).
            'status' => trim((string) $request->query->get('f_status', '')),
            'mortgage' => trim((string) $request->query->get('f_mortgage', '')),
            'seller' => trim((string) $request->query->get('f_seller', '')),
            'buyer' => trim((string) $request->query->get('f_buyer', '')),
            'number' => trim((string) $request->query->get('f_number', '')),
            'address' => trim((string) $request->query->get('f_address', '')),
            // Data creazione: intervallo "gg-mm-aaaa/gg-mm-aaaa" dal daterangepicker.
            'created' => trim((string) $request->query->get('f_created', '')),
        ];

        $qb = $this->slave()->getRepository(Practice::class)->createQueryBuilder('p')
            ->leftJoin('p.seller', 's')->addSelect('s')
            ->leftJoin('p.buyer', 'b')->addSelect('b')
            ->leftJoin('p.mark', 'm')->addSelect('m');

        // 12.3.3 Chi non è amministratore vede solo le pratiche in cui è stato abilitato.
        $user = $this->getUser();
        if ($user instanceof StaffUser && !$user->isAdmin()) {
            $qb->leftJoin('p.staff', 'ps')->andWhere('ps.id = :me')->setParameter('me', $user->getId());
        }

        $statuses = array_values(array_intersect(
            array_filter(explode(',', $filters['status'])),
            array_keys(Practice::STATUSES)
        ));
        if ($statuses !== []) {
            $qb->andWhere('p.status IN (:st)')->setParameter('st', $statuses);
        }
        [$from, $to] = $this->parseDateRange($filters['created']);
        if ($from !== null) {
            $qb->andWhere('p.createdAt >= :cfrom')->setParameter('cfrom', $from);
        }
        if ($to !== null) {
            // Fine giornata inclusa: createdAt è un datetime.
            $qb->andWhere('p.createdAt <= :cto')->setParameter('cto', $to->setTime(23, 59, 59));
        }
        if ($filters['mortgage'] !== '') {
            $qb->andWhere('p.mortgage = :mg')->setParameter('mg', $filters['mortgage'] === '1');
        }
        if ($filters['seller'] !== '') {
            $qb->andWhere("CONCAT(COALESCE(s.name, ''), ' ', COALESCE(s.surname, '')) LIKE :se")
                ->setParameter('se', '%' . $filters['seller'] . '%');
        }
        if ($filters['buyer'] !== '') {
            $qb->andWhere("CONCAT(COALESCE(b.name, ''), ' ', COALESCE(b.surname, '')) LIKE :bu")
                ->setParameter('bu', '%' . $filters['buyer'] . '%');
        }
        if ($filters['number'] !== '') {
            $qb->andWhere('p.number LIKE :nu')->setParameter('nu', '%' . $filters['number'] . '%');
        }
        if ($filters['address'] !== '') {
            $qb->andWhere('p.address LIKE :ad')->setParameter('ad', '%' . $filters['address'] . '%');
        }

        $records = $paginator->paginate($qb->getQuery(), $request->query->getInt('page', 1), self::PER_PAGE, [
            'defaultSortFieldName' => 'p.createdAt',
            'defaultSortDirection' => 'desc',
            'sortFieldAllowList' => ['p.status', 'p.createdAt', 'p.mortgage', 'p.number', 'p.address'],
        ]);

        return $this->render('role/agency/practices/index.html.twig', [
            'records' => $records,
            'filters' => $filters,
            'statuses' => Practice::STATUSES,
        ]);
    }

    /** 12.2 Nuova pratica. */
    #[Route('/nuova', name: 'agency_practice_new', methods: ['GET', 'POST'])]
    #[IsGranted(new Expression("is_granted('edit', 'practices')"))]
    public function new(Request $request): Response
    {
        $em = $this->slave();
        // 12.2 Dati già digitati: valorizzati solo se il POST non è andato a buon fine,
        // così l'utente ritrova il form come l'aveva lasciato invece di ricompilarlo.
        $old = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('practiceNew', (string) $request->request->get('_csrf_token'))) {
                return $this->redirectToRoute('agency_practice_new', [], Response::HTTP_SEE_OTHER);
            }

            $old = $this->submittedPracticeInput($request, $em);
            $practice = new Practice();
            if ($this->fill($practice, $request, $em)) {
                $practice->setNumber($this->nextNumber($em));
                $em->persist($practice);
                $em->flush();

                // 12.2.8 "Prosegui": la pratica nasce con le sue righe documentali (12.3.2.1).
                $added = $this->documentSync->sync($em, $practice);
                $em->flush();

                $this->addFlash('success', sprintf(
                    'Pratica %s creata con %d document%s da produrre.',
                    $practice->getNumber(),
                    $added,
                    $added === 1 ? 'o' : 'i'
                ));

                return $this->redirectToRoute('agency_practice_show', ['id' => $practice->getId()], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('role/agency/practices/new.html.twig', $this->formData($em) + ['old' => $old]);
    }

    /**
     * 12.2.1 / 12.2.2 Ricerca clienti per i campi venditore e acquirente.
     * Restituisce i primi 15 risultati per nome, cognome o codice fiscale.
     */
    #[Route('/api/cerca-cliente', name: 'agency_customer_search', methods: ['GET'])]
    public function searchCustomers(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));

        $qb = $this->slave()->getRepository(Customer::class)->createQueryBuilder('c')
            ->andWhere('c.active = true')
            ->orderBy('c.surname', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->setMaxResults(15);

        if ($q !== '') {
            $qb->andWhere("CONCAT(COALESCE(c.name, ''), ' ', COALESCE(c.surname, ''), ' ', COALESCE(c.fiscalCode, '')) LIKE :q")
                ->setParameter('q', '%' . $q . '%');
        }

        $items = array_map(static fn (Customer $c) => [
            'id' => $c->getId(),
            'label' => $c->getFullName() . ($c->getFiscalCode() ? ' — ' . $c->getFiscalCode() : ''),
        ], $qb->getQuery()->getResult());

        return $this->json($items);
    }

    /**
     * 12.2.9 Ricerca notai per l'autocomplete del form pratica: stessa meccanica delle
     * parti (12.2.1 / 12.2.2), ma l'"id" è l'e-mail, che è ciò che la pratica salva.
     */
    #[Route('/api/cerca-notaio', name: 'agency_notary_search', methods: ['GET'])]
    public function searchNotaries(Request $request): JsonResponse
    {
        $q = mb_strtolower(trim((string) $request->query->get('q', '')));

        $items = [];
        foreach ($this->notaries() as $notary) {
            $label = $notary->getFullName() . ' — ' . $notary->getEmail();
            if ($q !== '' && !str_contains(mb_strtolower($label), $q)) {
                continue;
            }
            $items[] = ['id' => $notary->getEmail(), 'label' => $label];
            if (count($items) === 15) {
                break;
            }
        }

        return $this->json($items);
    }

    /** 12.3 Scheda pratica. */
    #[Route('/{id}', name: 'agency_practice_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, PracticeNotifier $notifier): Response
    {
        $em = $this->slave();
        $practice = $this->requirePractice($id);

        // I dati del form servono anche qui: la modifica (12.3.1) è in modale sulla scheda.
        return $this->render('role/agency/practices/show.html.twig', $this->formData($em, $practice) + [
            // 12.3.3 Solo i non-amministratori vanno abilitati: gli admin entrano comunque.
            'staffMembers' => $em->getRepository(StaffUser::class)->findBy(['admin' => false, 'active' => true], ['surname' => 'ASC']),
            'assignedStaffIds' => $practice->getStaff()->map(fn (StaffUser $u) => $u->getId())->toArray(),
            // 12.3.2.7 Destinatari possibili della notifica.
            'recipients' => $notifier->recipientsFor($practice),
            // 12.3.2.1 Tipi di documento previsti ma senza riga: si propone di sincronizzare.
            'missingDocuments' => count($this->documentSync->missingFor($em, $practice)),
        ]);
    }

    /** 12.3.1 Modifica dei dati principali. */
    #[Route('/{id}/modifica', name: 'agency_practice_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'practices')"))]
    public function edit(int $id, Request $request): RedirectResponse
    {
        $em = $this->slave();
        $practice = $this->requireEditablePractice($id);

        if (!$this->isCsrfTokenValid('practiceEdit', (string) $request->request->get('_csrf_token'))) {
            return $this->redirectToRoute('agency_practice_show', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        if ($this->fill($practice, $request, $em)) {
            // Se la pratica passa a "con mutuo" servono anche i documenti dedicati.
            $added = $this->documentSync->sync($em, $practice);
            $em->flush();

            $this->addFlash('success', $added > 0
                ? sprintf('Pratica aggiornata: aggiunt%s %d document%s richiest%s dal mutuo.', $added === 1 ? 'o' : 'i', $added, $added === 1 ? 'o' : 'i', $added === 1 ? 'o' : 'i')
                : 'Pratica aggiornata.');
        }

        return $this->redirectToRoute('agency_practice_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    // ===================== 12.3.2 GESTIONE DOCUMENTALE =====================

    /**
     * 12.3.2.6 Nuovo allegato: come per il notaio (17.1.1.1.5.3) la riga documentale
     * arriva dal form e il file viene salvato con il "nome allegato" indicato.
     */
    #[Route('/{id}/allegati/nuovo', name: 'agency_document_new', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'practices')"))]
    public function documentNew(int $id, Request $request, DocumentStorage $storage): RedirectResponse
    {
        $practice = $this->requirePracticeWithEditableDocuments($id);
        if (!$this->isCsrfTokenValid('documentNew', (string) $request->request->get('_csrf_token'))) {
            return $this->backToDocuments($practice);
        }

        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');
        $name = trim((string) $request->request->get('name', ''));
        // Sono ammessi solo PDF (controllo sul contenuto, non sull'estensione dichiarata).
        $fileError = $storage->validationError($file);
        if ($fileError !== null) {
            $this->addFlash('danger', $fileError);

            return $this->backToDocuments($practice);
        }

        // L'allegato va appeso a una riga documentale della pratica (12.3.2.5).
        $row = $this->findRow($practice, (int) $request->request->get('practice_document_id'));
        if ($row === null) {
            $this->addFlash('danger', 'Seleziona il documento a cui allegare il file.');

            return $this->backToDocuments($practice);
        }

        if ($name === '') {
            $this->addFlash('danger', 'Indica il nome dell\'allegato: è il nome con cui il file viene salvato.');

            return $this->backToDocuments($practice);
        }

        $em = $this->slave();
        $document = (new Document())
            ->setName($name)
            ->setAgentNote(trim((string) $request->request->get('agent_note')) ?: null);
        $row->addDocument($document);
        $em->persist($document);

        try {
            // Il file non conserva il nome originale: prende il "nome allegato" del form.
            $meta = $storage->store($this->dbName(), $practice, $file, $name);
            $document->setOriginalFilename($meta['name'])
                ->setStoragePath($meta['path'])
                ->setMimeType($meta['mime'])
                ->setSizeBytes($meta['size']);

            // Caricare il primo file porta la riga da "da caricare" a "da verificare".
            if ($row->getStatus() === PracticeDocument::STATUS_TO_UPLOAD) {
                $row->setStatus(PracticeDocument::STATUS_TO_VERIFY);
            }
            $em->flush();
            $this->addFlash('success', 'Allegato caricato su "' . $row->getLabel() . '".');
        } catch (\Throwable $e) {
            $em->remove($document);
            $em->flush();
            $this->addFlash('danger', 'Caricamento non riuscito: ' . $e->getMessage());
        }

        return $this->backToDocuments($practice);
    }

    /**
     * 12.3.2.1 Sincronizza le righe documentali col catalogo (13.1): aggiunge quelle dei
     * tipi entrati dopo l'apertura della pratica. Le righe esistenti non si toccano,
     * quindi stato, allegati e mostra/nascondi restano dove sono.
     */
    #[Route('/{id}/documenti/sincronizza', name: 'agency_practice_documents_sync', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'practices')"))]
    public function documentsSync(int $id, Request $request): RedirectResponse
    {
        $practice = $this->requirePracticeWithEditableDocuments($id);
        if (!$this->isCsrfTokenValid('practiceDocumentsSync', (string) $request->request->get('_csrf_token'))) {
            return $this->backToDocuments($practice);
        }

        $em = $this->slave();
        $added = $this->documentSync->sync($em, $practice);
        $em->flush();

        $this->addFlash(
            $added > 0 ? 'success' : 'warning',
            $added > 0
                ? sprintf('Aggiunt%s %d document%s previst%s dalle configurazioni.', $added === 1 ? 'o' : 'i', $added, $added === 1 ? 'o' : 'i', $added === 1 ? 'o' : 'i')
                : 'I documenti della pratica sono già allineati alle configurazioni.'
        );

        return $this->backToDocuments($practice);
    }

    /** 12.3.2.5.5 Modifica di un allegato: nome e note dell'agente. */
    #[Route('/{id}/allegati/{did}/modifica', name: 'agency_document_edit', methods: ['POST'], requirements: ['id' => '\d+', 'did' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'practices')"))]
    public function documentEdit(int $id, int $did, Request $request): RedirectResponse
    {
        $this->requirePracticeWithEditableDocuments($id);
        [$practice, $document] = $this->requireDocument($id, $did);
        if ($this->isCsrfTokenValid('documentEdit', (string) $request->request->get('_csrf_token'))) {
            $name = trim((string) $request->request->get('name'));
            $document->setName($name !== '' ? $name : $document->getName())
                ->setAgentNote(trim((string) $request->request->get('agent_note')) ?: null);
            $this->slave()->flush();
            $this->addFlash('success', 'Allegato aggiornato.');
        }

        return $this->backToDocuments($practice);
    }

    /** 12.3.2.5.6 Elimina allegato (record + file). */
    #[Route('/{id}/allegati/{did}/elimina', name: 'agency_document_delete', methods: ['POST'], requirements: ['id' => '\d+', 'did' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'practices')"))]
    public function documentDelete(int $id, int $did, Request $request, DocumentStorage $storage): RedirectResponse
    {
        $this->requirePracticeWithEditableDocuments($id);
        [$practice, $document] = $this->requireDocument($id, $did);
        if ($this->isCsrfTokenValid('delete', (string) $request->request->get('_csrf_token'))) {
            $storage->delete($this->dbName(), $document->getStoragePath());
            $em = $this->slave();
            // Se la riga resta senza allegati torna "da caricare": non c'è più nulla da verificare.
            $row = $document->getPracticeDocument();
            $row?->removeDocument($document)->resetStatusIfEmpty();
            $em->remove($document);
            $em->flush();
            $this->addFlash('success', 'Allegato eliminato.');
        }

        return $this->backToDocuments($practice);
    }

    /** 12.3.2.5.1 Scarica un allegato. */
    #[Route('/{id}/allegati/{did}/scarica', name: 'agency_document_download', methods: ['GET'], requirements: ['id' => '\d+', 'did' => '\d+'])]
    public function documentDownload(int $id, int $did, DocumentStorage $storage): Response
    {
        [, $document] = $this->requireDocument($id, $did);

        $absolute = $storage->absolutePath($this->dbName(), (string) $document->getStoragePath());
        if (!$document->hasFile() || !is_file($absolute)) {
            throw $this->createNotFoundException('File non presente sul server.');
        }

        $response = new BinaryFileResponse($absolute);
        $response->setContentDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $document->getOriginalFilename() ?: $document->getName()
        );

        return $response;
    }

    /**
     * Anteprima dell'allegato: lo stesso file servito inline, per l'iframe del modale.
     * Gli allegati sono solo PDF, quindi lo mostra il viewer del browser.
     */
    #[Route('/{id}/allegati/{did}/anteprima', name: 'agency_document_preview', methods: ['GET'], requirements: ['id' => '\d+', 'did' => '\d+'])]
    public function documentPreview(int $id, int $did, DocumentStorage $storage): Response
    {
        [, $document] = $this->requireDocument($id, $did);

        $absolute = $storage->absolutePath($this->dbName(), (string) $document->getStoragePath());
        if (!$document->hasFile() || !is_file($absolute)) {
            throw $this->createNotFoundException('File non presente sul server.');
        }

        $response = new BinaryFileResponse($absolute);
        $response->headers->set('Content-Type', $document->getMimeType() ?: DocumentStorage::ALLOWED_MIME);
        $response->setContentDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            $document->getOriginalFilename() ?: ($document->getName() . '.pdf')
        );

        return $response;
    }

    /** 12.3.2.7 Invia notifica di aggiornamento file. */
    #[Route('/{id}/notifica', name: 'agency_practice_notify', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'practices')"))]
    public function notify(int $id, Request $request, PracticeNotifier $notifier): RedirectResponse
    {
        $practice = $this->requireEditablePractice($id);
        if (!$this->isCsrfTokenValid('practiceNotify', (string) $request->request->get('_csrf_token'))) {
            return $this->backToDocuments($practice);
        }

        $to = trim((string) $request->request->get('recipient'));
        $message = trim((string) $request->request->get('message'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || $message === '') {
            $this->addFlash('danger', 'Seleziona un destinatario e scrivi il messaggio.');

            return $this->backToDocuments($practice);
        }
        if (!array_key_exists($to, $notifier->recipientsFor($practice))) {
            $this->addFlash('danger', 'Destinatario non valido per questa pratica.');

            return $this->backToDocuments($practice);
        }

        $sent = $notifier->notifyFileUpdate($practice, $to, $message, $this->staffName());
        $this->addFlash(
            $sent ? 'success' : 'warning',
            $sent
                ? 'Notifica inviata a ' . $to . '.'
                : 'Notifica non inviata: il trasporto email ha rifiutato il messaggio.'
        );

        return $this->backToDocuments($practice);
    }

    // ===================== 12.3.3 STAFF SULLA PRATICA =====================

    /** 12.3.3.1 Seleziona chi accede alla pratica oltre agli amministratori. */
    #[Route('/{id}/staff', name: 'agency_practice_staff', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'practices')"))]
    public function staff(int $id, Request $request): RedirectResponse
    {
        $practice = $this->requireEditablePractice($id);
        if (!$this->isCsrfTokenValid('practiceStaff', (string) $request->request->get('_csrf_token'))) {
            return $this->backToTab($practice, 'staff');
        }

        $em = $this->slave();
        $practice->clearStaff();
        foreach (array_map('intval', (array) $request->request->all('staff')) as $userId) {
            $member = $userId > 0 ? $em->getRepository(StaffUser::class)->find($userId) : null;
            // Gli amministratori hanno già accesso: non ha senso elencarli.
            if ($member !== null && !$member->isAdmin()) {
                $practice->addStaff($member);
            }
        }
        $em->flush();
        $this->addFlash('success', 'Accessi alla pratica aggiornati.');

        return $this->backToTab($practice, 'staff');
    }

    // ===================== 12.3.4 AVVISI =====================

    /** 12.3.4.2 Nuovo avviso. */
    #[Route('/{id}/avvisi/nuovo', name: 'agency_alert_new', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'practices')"))]
    public function alertNew(int $id, Request $request): RedirectResponse
    {
        $practice = $this->requireEditablePractice($id);
        if (!$this->isCsrfTokenValid('alertNew', (string) $request->request->get('_csrf_token'))) {
            return $this->backToAlerts($practice);
        }

        $alert = new PracticeAlert();
        if ($this->fillAlert($alert, $request)) {
            $em = $this->slave();
            $practice->addAlert($alert);
            $em->persist($alert);
            $em->flush();
            $this->addFlash('success', 'Avviso creato.');
        }

        return $this->backToAlerts($practice);
    }

    /** 12.3.4.1.1 Modifica avviso. */
    #[Route('/{id}/avvisi/{aid}/modifica', name: 'agency_alert_edit', methods: ['POST'], requirements: ['id' => '\d+', 'aid' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'practices')"))]
    public function alertEdit(int $id, int $aid, Request $request): RedirectResponse
    {
        [$practice, $alert] = $this->requireAlert($id, $aid);
        if ($this->isCsrfTokenValid('alertEdit', (string) $request->request->get('_csrf_token')) && $this->fillAlert($alert, $request)) {
            $this->slave()->flush();
            $this->addFlash('success', 'Avviso aggiornato.');
        }

        return $this->backToAlerts($practice);
    }

    /** 12.3.4.1.2 Elimina avviso. */
    #[Route('/{id}/avvisi/{aid}/elimina', name: 'agency_alert_delete', methods: ['POST'], requirements: ['id' => '\d+', 'aid' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'practices')"))]
    public function alertDelete(int $id, int $aid, Request $request): RedirectResponse
    {
        [$practice, $alert] = $this->requireAlert($id, $aid);
        if ($this->isCsrfTokenValid('delete', (string) $request->request->get('_csrf_token'))) {
            $em = $this->slave();
            $em->remove($alert);
            $em->flush();
            $this->addFlash('success', 'Avviso eliminato.');
        }

        return $this->backToAlerts($practice);
    }

    // ===================== 12.3.5 / 12.3.6 ARCHIVIAZIONE =====================

    /** 12.3.5 Archivia la pratica, solo col via libera del notaio (12.3.5.2). */
    #[Route('/{id}/archivia', name: 'agency_practice_archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'practices')"))]
    public function archive(int $id, Request $request): RedirectResponse
    {
        $practice = $this->requirePractice($id);
        if (!$this->isCsrfTokenValid('practiceArchive', (string) $request->request->get('_csrf_token'))) {
            return $this->redirectToRoute('agency_practice_show', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        if (!$practice->canBeArchived()) {
            $this->addFlash('danger', $practice->isArchived()
                ? 'La pratica è già archiviata.'
                : 'Il notaio non ha ancora messo la pratica in "archiviabile": non puoi archiviarla.');

            return $this->redirectToRoute('agency_practice_show', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        $practice->setStatus(Practice::STATUS_ARCHIVED);
        $this->slave()->flush();
        $this->addFlash('success', 'Pratica ' . $practice->getNumber() . ' archiviata. Scarica l\'archivio per conservarne una copia.');

        return $this->redirectToRoute('agency_practice_show', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    /** 12.3.6 Scarica l'archivio: tutti gli allegati della pratica in uno ZIP. */
    #[Route('/{id}/archivio', name: 'agency_practice_archive_download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function archiveDownload(int $id, DocumentStorage $storage): Response
    {
        $practice = $this->requirePractice($id);

        $zipPath = tempnam(sys_get_temp_dir(), 'instrumento_') ?: throw new \RuntimeException('Impossibile creare il file temporaneo.');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Impossibile creare l\'archivio.');
        }

        $added = 0;
        foreach ($practice->getPracticeDocuments() as $row) {
            foreach ($row->getDocuments() as $document) {
                $absolute = $storage->absolutePath($this->dbName(), (string) $document->getStoragePath());
                if (!$document->hasFile() || !is_file($absolute)) {
                    continue;
                }
                // Una cartella per tipo di documento, così l'archivio resta leggibile.
                $folder = preg_replace('/[^\w\s.-]+/u', '', $row->getLabel()) ?: 'Documenti';
                $zip->addFile($absolute, $folder . '/' . ($document->getOriginalFilename() ?: $document->getName()));
                ++$added;
            }
        }

        if ($added === 0) {
            $zip->addFromString('LEGGIMI.txt', 'La pratica ' . $practice->getNumber() . " non ha allegati.\n");
        }
        $zip->close();

        $response = new BinaryFileResponse($zipPath);
        $response->setContentDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            'pratica-' . str_replace('/', '-', $practice->getNumber()) . '.zip'
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }

    // ===================== SUPPORTO =====================

    private function slave(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManager('slave');

        return $em;
    }

    /** Nome del database dell'agenzia corrente: serve ai percorsi dei file. */
    private function dbName(): string
    {
        $company = $this->companyService->getCurrentCompany();
        if ($company === null) {
            throw $this->createAccessDeniedException('Contesto agenzia non disponibile.');
        }

        return (string) $company->getDbName();
    }

    private function staffName(): string
    {
        $user = $this->getUser();

        return $user instanceof StaffUser ? $user->getFullName() : 'Agenzia';
    }

    /**
     * 12.3.3 Oltre a esistere, la pratica dev'essere accessibile all'utente: gli
     * amministratori vedono tutto, gli altri solo quelle in cui sono inseriti.
     */
    private function requirePractice(int $id): Practice
    {
        $practice = $this->slave()->getRepository(Practice::class)->find($id);
        if ($practice === null) {
            throw $this->createNotFoundException('Pratica non trovata.');
        }

        $user = $this->getUser();
        if ($user instanceof StaffUser && !$practice->isAccessibleBy($user)) {
            throw $this->createAccessDeniedException('Non sei fra le persone abilitate a questa pratica.');
        }

        return $practice;
    }

    /**
     * 12.3.5 Come requirePractice(), ma rifiuta le pratiche archiviate: sono in sola
     * lettura. Nascondere i pulsanti non basta, la POST va bloccata anche qui.
     */
    private function requireEditablePractice(int $id): Practice
    {
        $practice = $this->requirePractice($id);
        if ($practice->isArchived()) {
            throw $this->createAccessDeniedException('La pratica è archiviata: non è più modificabile.');
        }

        return $practice;
    }

    /**
     * 12.3.2 Come requireEditablePractice(), ma per i documenti: sono modificabili
     * solo a pratica aperta. Nascondere i pulsanti non basta, la POST va bloccata.
     */
    private function requirePracticeWithEditableDocuments(int $id): Practice
    {
        $practice = $this->requirePractice($id);
        if (!$practice->areDocumentsEditable()) {
            throw $this->createAccessDeniedException(sprintf(
                'La pratica è %s: i documenti non sono più modificabili.',
                mb_strtolower($practice->getStatusLabel())
            ));
        }

        return $practice;
    }

    /** Riga documentale della pratica, per id; null se non le appartiene. */
    private function findRow(Practice $practice, int $rid): ?PracticeDocument
    {
        foreach ($practice->getPracticeDocuments() as $row) {
            if ((int) $row->getId() === $rid) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array{0: Practice, 1: Document}
     */
    private function requireDocument(int $id, int $did): array
    {
        $practice = $this->requirePractice($id);
        foreach ($practice->getPracticeDocuments() as $row) {
            foreach ($row->getDocuments() as $document) {
                if ((int) $document->getId() === $did) {
                    return [$practice, $document];
                }
            }
        }

        throw $this->createNotFoundException('Allegato non trovato in questa pratica.');
    }

    /**
     * @return array{0: Practice, 1: PracticeAlert}
     */
    private function requireAlert(int $id, int $aid): array
    {
        $practice = $this->requireEditablePractice($id);
        foreach ($practice->getAlerts() as $alert) {
            if ((int) $alert->getId() === $aid) {
                return [$practice, $alert];
            }
        }

        throw $this->createNotFoundException('Avviso non trovato in questa pratica.');
    }

    private function backToDocuments(Practice $practice): RedirectResponse
    {
        return $this->backToTab($practice, 'documenti');
    }

    /** Torna alla scheda sulla tab da cui si è partiti (la ripristina l'hash). */
    private function backToTab(Practice $practice, string $tab): RedirectResponse
    {
        return $this->redirect(
            $this->generateUrl('agency_practice_show', ['id' => $practice->getId()]) . '#tab-' . $tab,
            Response::HTTP_SEE_OTHER
        );
    }

    private function backToAlerts(Practice $practice): RedirectResponse
    {
        return $this->backToTab($practice, 'avvisi');
    }

    /**
     * 12.2.5 Città e CAP dal picker geografico (dati del master), entrambi obbligatori.
     * Il campo città è readonly (si compila dal modale) e quindi il browser non lo
     * valida: il controllo che conta è questo.
     */
    private function fillGeo(Practice $practice, Request $request): bool
    {
        $master = $this->registry->getManager('master');

        $cityId = (int) $request->request->get('city_id');
        $city = $cityId > 0 ? $master->getRepository(City::class)->find($cityId) : null;
        if ($city === null) {
            $this->addFlash('danger', 'Seleziona la città dell\'immobile.');

            return false;
        }

        $zipId = (int) $request->request->get('zip_id');
        $zip = $zipId > 0 ? $master->getRepository(Zip::class)->find($zipId) : null;
        // Il CAP deve essere fra quelli del comune scelto (relazione molti-a-molti).
        if ($zip === null || !$zip->getCities()->contains($city)) {
            $this->addFlash('danger', 'Seleziona un CAP valido per il comune scelto.');

            return false;
        }

        $practice->setCity($city->getName())->setCityRefId((int) $city->getId())
            ->setZip($zip->getCode())->setZipRefId((int) $zip->getId());

        return true;
    }

    /** 12.3.4.2 Campi dell'avviso. */
    private function fillAlert(PracticeAlert $alert, Request $request): bool
    {
        $remindAt = $this->parseDate((string) $request->request->get('remind_at'));
        if ($remindAt === false || $remindAt === null) {
            $this->addFlash('danger', 'La data dell\'avviso non è valida: usa il formato gg-mm-aaaa.');

            return false;
        }

        $message = trim((string) $request->request->get('message'));
        if ($message === '') {
            $this->addFlash('danger', 'Il messaggio dell\'avviso è obbligatorio.');

            return false;
        }

        $alert->setRemindAt($remindAt)->setMessage($message);

        return true;
    }

    /**
     * Dati per il form di inserimento/modifica.
     *
     * @return array<string, mixed>
     */
    private function formData(EntityManagerInterface $em, ?Practice $practice = null): array
    {
        return [
            'practice' => $practice,
            'customers' => $em->getRepository(Customer::class)->findBy(['active' => true], ['surname' => 'ASC', 'name' => 'ASC']),
            'marks' => $em->getRepository(PracticeMark::class)->findBy([], ['value' => 'ASC']),
            'tags' => $em->getRepository(PracticeTag::class)->findBy([], ['value' => 'ASC']),
            // 12.2.9 I notai stanno nel master: li gestisce l'amministrazione.
            'notaries' => $this->notaries(),
        ];
    }

    /**
     * 12.2 Dati appena inviati dal form pratica, pronti per essere rimessi nei campi.
     *
     * Venditore, acquirente e città si scelgono da ricerca o da picker: nel POST arriva
     * solo l'id, quindi l'etichetta visibile va ricostruita qui, altrimenti tornerebbe
     * un campo vuoto accanto a un id valorizzato.
     *
     * @return array<string, mixed>
     */
    private function submittedPracticeInput(Request $request, EntityManagerInterface $em): array
    {
        $data = $request->request;
        $customers = $em->getRepository(Customer::class);
        $labels = [];
        foreach (['seller', 'buyer'] as $role) {
            $id = (int) $data->get($role . '_id');
            $customer = $id > 0 ? $customers->find($id) : null;
            $labels[$role] = $customer === null
                ? ''
                : $customer->getFullName() . ($customer->getFiscalCode() ? ' — ' . $customer->getFiscalCode() : '');
        }

        $cityId = (int) $data->get('city_id');
        $city = $cityId > 0 ? $this->registry->getManager('master')->getRepository(City::class)->find($cityId) : null;

        return [
            'seller_id' => (int) $data->get('seller_id') ?: '',
            'seller_label' => $labels['seller'],
            'buyer_id' => (int) $data->get('buyer_id') ?: '',
            'buyer_label' => $labels['buyer'],
            'created_at' => trim((string) $data->get('created_at')),
            'mark_id' => (int) $data->get('mark_id'),
            'mortgage' => $data->getBoolean('mortgage'),
            'address' => trim((string) $data->get('address')),
            'city_id' => $city !== null ? (int) $city->getId() : '',
            'city_name' => $city !== null ? (string) $city->getName() : '',
            'zip_id' => (int) $data->get('zip_id') ?: '',
            'notary_email' => mb_strtolower(trim((string) $data->get('notary_email'))),
            'notary_label' => $this->notaryLabel(mb_strtolower(trim((string) $data->get('notary_email')))),
            'subject' => trim((string) $data->get('subject')),
            'tags' => array_values(array_filter(array_map('intval', (array) $data->all('tags')))),
        ];
    }

    /**
     * 12.2.9 Notai assegnabili: utenti master attivi con ROLE_NOTARY.
     *
     * @return NotaryUser[]
     */
    private function notaries(): array
    {
        /** @var \App\Repository\Master\UserRepository $repo */
        $repo = $this->registry->getManager('master')->getRepository(NotaryUser::class);

        return $repo->findActiveNotaries();
    }

    /** Etichetta del notaio (come la mostra l'autocomplete); l'e-mail se non lo trova. */
    private function notaryLabel(string $email): string
    {
        if ($email === '') {
            return '';
        }

        foreach ($this->notaries() as $notary) {
            if (mb_strtolower((string) $notary->getEmail()) === $email) {
                return $notary->getFullName() . ' — ' . $notary->getEmail();
            }
        }

        return $email;
    }

    /**
     * E-mail dei notai assegnabili, in minuscolo.
     *
     * @return string[]
     */
    private function notaryEmails(): array
    {
        return array_map(
            static fn (NotaryUser $u) => mb_strtolower((string) $u->getEmail()),
            $this->notaries(),
        );
    }

    /** Numero pratica progressivo per anno: P-0001/2026. */
    private function nextNumber(EntityManagerInterface $em): string
    {
        $year = (int) (new \DateTimeImmutable())->format('Y');
        $count = (int) $em->getRepository(Practice::class)->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.number LIKE :y')
            ->setParameter('y', '%/' . $year)
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('P-%04d/%d', $count + 1, $year);
    }

    /** 12.2.1–12.2.7 Campi della pratica. */
    private function fill(Practice $practice, Request $request, EntityManagerInterface $em): bool
    {
        $sellerId = (int) $request->request->get('seller_id');
        $buyerId = (int) $request->request->get('buyer_id');
        if ($sellerId <= 0 || $buyerId <= 0) {
            $this->addFlash('danger', 'Seleziona sia il venditore sia l\'acquirente.');

            return false;
        }
        if ($sellerId === $buyerId) {
            $this->addFlash('danger', 'Venditore e acquirente non possono essere la stessa persona.');

            return false;
        }

        $customers = $em->getRepository(Customer::class);
        $seller = $customers->find($sellerId);
        $buyer = $customers->find($buyerId);
        if ($seller === null || $buyer === null) {
            $this->addFlash('danger', 'Cliente non trovato.');

            return false;
        }

        $createdAt = $this->parseDate((string) $request->request->get('created_at'));
        if ($createdAt === false || $createdAt === null) {
            $this->addFlash('danger', 'La data di creazione non è valida: usa il formato gg-mm-aaaa.');

            return false;
        }

        $address = trim((string) $request->request->get('address'));
        if ($address === '') {
            $this->addFlash('danger', 'L\'indirizzo dell\'immobile è obbligatorio.');

            return false;
        }

        // 12.2.5 Città e CAP: obbligatori, quindi si validano prima di toccare la pratica.
        if (!$this->fillGeo($practice, $request)) {
            return false;
        }

        // 12.2.9 Notaio assegnato: vale solo se è uno di quelli presenti, così un valore
        // arrivato a mano non finisce in pratica (ed è quello che dà l'accesso, vedi 17.1).
        $notaryEmail = mb_strtolower(trim((string) $request->request->get('notary_email')));
        if ($notaryEmail !== '' && !in_array($notaryEmail, $this->notaryEmails(), true)) {
            $this->addFlash('danger', 'Il notaio selezionato non è più disponibile.');

            return false;
        }

        $practice->setSeller($seller)
            ->setBuyer($buyer)
            ->setCreatedAt($createdAt)
            ->setMortgage($request->request->getBoolean('mortgage'))
            ->setAddress($address)
            ->setNotaryEmail($notaryEmail ?: null)
            ->setSubject(trim((string) $request->request->get('subject')) ?: null);

        // 12.2.7 Contrassegno (uno) e 12.2.6 tag (molti).
        $markId = (int) $request->request->get('mark_id');
        $practice->setMark($markId > 0 ? $em->getRepository(PracticeMark::class)->find($markId) : null);

        $practice->clearTags();
        foreach (array_map('intval', (array) $request->request->all('tags')) as $tagId) {
            $tag = $tagId > 0 ? $em->getRepository(PracticeTag::class)->find($tagId) : null;
            if ($tag !== null) {
                $practice->addTag($tag);
            }
        }

        return true;
    }
}
