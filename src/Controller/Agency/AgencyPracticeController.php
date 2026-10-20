<?php

namespace App\Controller\Agency;

use App\Entity\City;
use App\Entity\Company;
use App\Entity\Customer;
use App\Entity\Document;
use App\Entity\Practice;
use App\Entity\PracticeAlert;
use App\Entity\PracticeDocument;
use App\Entity\PracticeMark;
use App\Entity\PracticeTag;
use App\Entity\User;
use App\Entity\Zip;
use App\Form\DocumentEditType;
use App\Form\Model\PracticeDocumentUpload;
use App\Form\Model\PracticeNotify;
use App\Form\PracticeAlertType;
use App\Form\PracticeDocumentUploadType;
use App\Form\PracticeNotifyType;
use App\Form\PracticeType;
use App\Repository\PracticeRepository;
use App\Repository\UserRepository;
use App\Service\CompanyService;
use App\Service\DocumentStorageService;
use App\Service\FilterService;
use App\Service\PracticeDocumentSyncService;
use App\Service\PracticeNotifierService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * 12. Pratiche dell'agenzia: elenco (12.1), inserimento (12.2) e scheda (12.3).
 * Sono dell'agenzia (company_id); il notaio assegnato le lavora dalla sua area (17).
 */
#[Route('/agenzia/pratiche')]
#[IsGranted('ROLE_USER')]
#[IsGranted(new Expression("is_granted('view', 'practices')"), message: 'Non hai accesso alle pratiche.')]
class AgencyPracticeController extends AbstractController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly PracticeDocumentSyncService $documentSync,
        private readonly CompanyService $companyService,
        private readonly UserRepository $users,
    ) {
    }

    /** 12.1 Elenco pratiche, a schede per stato (la scheda attiva è in sessione, vedi agencyPracticeTab). */
    #[Route('/lista', name: 'agency_practices', methods: ['GET'])]
    public function agencyPractices(Request $request, PaginatorInterface $paginator): Response
    {
        $filters = FilterService::practiceFilter($request);

        // 12.3.3 Chi non è amministratore vede solo le pratiche in cui è stato abilitato.
        $user = $this->getUser();
        $onlyStaffId = $user instanceof User && !$user->isAdmin() ? $user->getId() : null;

        /** @var PracticeRepository $practices */
        $practices = $this->em()->getRepository(Practice::class);
        $records = $paginator->paginate($practices->queryAgencyFiltered($this->company(), $filters, $onlyStaffId), FilterService::page($request, 'agency_practices'), self::PER_PAGE);

        return $this->render('role/agency/practice/list.html.twig', [
            'records' => $records,
            'filters' => $filters,
            'statuses' => Practice::STATUSES,
            // Contatori delle schede: con gli stessi filtri, stato escluso
            'statusCounts' => $practices->countAgencyByStatus($this->company(), $filters, $onlyStaffId),
            // Opzioni dei filtri contrassegno e tag (cataloghi dell'agenzia)
            'marks' => $this->em()->getRepository(PracticeMark::class)->findBy(['company' => $this->company()], ['value' => 'ASC']),
            'tags' => $this->em()->getRepository(PracticeTag::class)->findBy(['company' => $this->company()], ['value' => 'ASC']),
        ]);
    }

    /**
     * Cambio scheda dell'elenco pratiche: lo stato scelto va in sessione (non nell'URL) e
     * l'elenco riparte dalla prima pagina, con gli altri filtri invariati.
     */
    #[Route('/stato/{status}', name: 'agency_practice_tab', methods: ['GET'], requirements: ['status' => 'open|completed|archived'])]
    public function agencyPracticeTab(string $status, Request $request): RedirectResponse
    {
        $request->getSession()->set(FilterService::PRACTICE_TAB, $status);
        $request->getSession()->remove('agency_practices_page');

        return $this->redirectToRoute('agency_practices');
    }

    /**
     * 12.2 Nuova pratica: la stessa rotta mostra e gestisce il form (CONVENZIONI 6). Se l'invio
     * non è valido la pagina si ripresenta con i campi segnati e i valori inseriti, compresi
     * gli autocomplete (id negli hidden, etichetta nei campi di ricerca) e il picker città.
     */
    #[Route('/nuova', name: 'agency_practice_new', methods: ['GET', 'POST'])]
    #[IsGranted(new Expression("is_granted('edit', 'practices')"))]
    public function agencyPracticeNew(Request $request, FormFactoryInterface $formFactory): Response
    {
        $em = $this->em();
        // 12.2.3 La data di creazione parte da oggi.
        $practice = (new Practice())->setCompany($this->company())->setCreatedAt(new \DateTimeImmutable('today'));
        $form = $formFactory->createNamed('practice_new', PracticeType::class, $practice);
        $form->handleRequest($request);

        $geo = $form->isSubmitted() ? $this->resolveGeo($request, $form) : null;
        if ($form->isSubmitted()) {
            $this->checkNotary($form);
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $this->completePractice($practice, $geo, $form);
            $practice->setNumber($this->nextNumber($em));
            $em->getRepository(Practice::class)->add($practice);

            // 12.2.8 "Prosegui": la pratica nasce con le sue righe documentali (12.3.2.1).
            $added = $this->documentSync->sync($em, $practice);
            $em->flush();

            $this->addFlash('notice_success', sprintf(
                'Pratica %s creata con %d document%s da produrre.',
                $practice->getNumber(),
                $added,
                $added === 1 ? 'o' : 'i'
            ));

            return $this->redirectToRoute('agency_practice_sheet', ['id' => $practice->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('role/agency/practice/new.html.twig', [
            'form' => $form->createView(),
            'customerCount' => $em->getRepository(Customer::class)->count(['active' => true]),
            'notaries' => $this->notaries(),
            'geo' => $this->geoView($request, $geo, null),
        ]);
    }

    /**
     * 12.2.1 / 12.2.2 Ricerca clienti per i campi venditore e acquirente.
     * Restituisce i primi 15 risultati per nome, cognome o codice fiscale.
     */
    #[Route('/api/cerca-cliente', name: 'agency_practice_customer_search', methods: ['GET'])]
    public function agencyPracticeCustomerSearch(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));

        $qb = $this->em()->getRepository(Customer::class)->createQueryBuilder('c')
            ->andWhere('c.company = :company')->setParameter('company', $this->company())
            ->andWhere('c.active = true')
            ->orderBy('c.surname', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->setMaxResults(15);

        if ($q !== '') {
            $qb->andWhere("CONCAT(COALESCE(c.name, ''), ' ', COALESCE(c.surname, ''), ' ', COALESCE(c.fiscalCode, '')) LIKE :q")
                ->setParameter('q', '%' . $q . '%');
        }

        $items = array_map(fn (Customer $c) => [
            'id' => $c->getId(),
            'label' => $this->customerLabel($c),
        ], $qb->getQuery()->getResult());

        return $this->json($items);
    }

    /**
     * 12.2.9 Ricerca notai per l'autocomplete del form pratica: stessa meccanica delle
     * parti (12.2.1 / 12.2.2), ma l'"id" è l'e-mail, che è ciò che la pratica salva.
     */
    #[Route('/api/cerca-notaio', name: 'agency_practice_notary_search', methods: ['GET'])]
    public function agencyPracticeNotarySearch(Request $request): JsonResponse
    {
        $q = mb_strtolower(trim((string) $request->query->get('q', '')));

        $items = [];
        foreach ($this->notaries() as $notary) {
            $label = $notary->getFullName() . ' (' . $notary->getEmail() . ')';
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

    /**
     * 12.3 Scheda pratica, con i form dei modali (CONVENZIONI 6, schema degli account esterni):
     * modifica dei dati principali (12.3.1), nuovo allegato (12.3.2.6) e modifica allegato
     * (12.3.2.5.5, ?edit_document=ID), notifica (12.3.2.7), nuovo avviso e modifica avviso
     * (12.3.4, ?edit_alert=ID). I form si inviano a questa pagina, che salva e torna alla
     * scheda sulla tab giusta; se non sono validi la scheda si ripresenta col modale aperto.
     */
    #[Route('/scheda/{id}', name: 'agency_practice_sheet', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function agencyPracticeSheet(int $id, Request $request, PracticeNotifierService $notifier, DocumentStorageService $storage, FormFactoryInterface $formFactory): Response
    {
        $em = $this->em();
        $practice = $this->requirePractice($id);

        // I form si gestiscono solo con il permesso di modifica e a pratica non archiviata
        // (12.3.5); quelli dei documenti solo finché i documenti sono modificabili (12.3.2).
        // Nascondere i pulsanti non basta: la POST va bloccata anche qui.
        $canEdit = $this->isGranted('edit', 'practices') && !$practice->isArchived();
        $canEditDocuments = $canEdit && $practice->areDocumentsEditable();
        if ($request->isMethod('POST')) {
            if (!$canEdit) {
                throw $this->createAccessDeniedException($practice->isArchived() ? 'La pratica è archiviata: non è più modificabile.' : 'Non puoi modificare le pratiche.');
            }
            if (!$canEditDocuments && ($request->request->has('practice_document_new') || $request->request->has('practice_document_edit'))) {
                throw $this->createAccessDeniedException(sprintf(
                    'La pratica è %s: i documenti non sono più modificabili.',
                    mb_strtolower($practice->getStatusLabel())
                ));
            }
        }
        // Tab da riaprire se un form torna indietro con errori.
        $openTab = null;

        // ===== 12.3.1 Modifica dei dati principali =====
        $formEdit = $formFactory->createNamed('practice_edit', PracticeType::class, $practice);
        $this->presetPracticeLabels($formEdit, $practice);
        if ($canEdit) {
            $formEdit->handleRequest($request);
        }
        $geo = $formEdit->isSubmitted() ? $this->resolveGeo($request, $formEdit) : null;
        if ($formEdit->isSubmitted()) {
            $this->checkNotary($formEdit);
            if ($formEdit->isValid()) {
                $this->completePractice($practice, $geo, $formEdit);
                // Se la pratica passa a "con mutuo" servono anche i documenti dedicati.
                $added = $this->documentSync->sync($em, $practice);
                $em->flush();

                $this->addFlash('notice_success', $added > 0
                    ? sprintf('Pratica aggiornata: aggiunt%s %d document%s richiest%s dal mutuo.', $added === 1 ? 'o' : 'i', $added, $added === 1 ? 'o' : 'i', $added === 1 ? 'o' : 'i')
                    : 'Pratica aggiornata.');

                return $this->redirectToRoute('agency_practice_sheet', ['id' => $id], Response::HTTP_SEE_OTHER);
            }
            // Il form ha già scritto i valori non validi sulla pratica: la scheda deve mostrare
            // quelli salvati (il modale tiene comunque i valori inseriti).
            $em->refresh($practice);
        }

        // ===== 12.3.2.6 Nuovo allegato =====
        $upload = new PracticeDocumentUpload();
        $formDocumentNew = $formFactory->createNamed('practice_document_new', PracticeDocumentUploadType::class, $upload, [
            'rows' => $practice->getPracticeDocuments()->toArray(),
        ]);
        if ($canEditDocuments) {
            $formDocumentNew->handleRequest($request);
            if ($formDocumentNew->isSubmitted() && $formDocumentNew->isValid()) {
                $row = $upload->getPracticeDocument();
                $document = (new Document())
                    ->setName(trim($upload->getName()))
                    ->setAgentNote($this->nullIfBlank($upload->getNote()));
                if ($this->storeUpload($storage, $practice, $row, $document, $upload, $formDocumentNew)) {
                    $this->addFlash('notice_success', 'Allegato caricato su "' . $row->getLabel() . '".');

                    return $this->backToDocuments($practice);
                }
            }
            if ($formDocumentNew->isSubmitted()) {
                $openTab = 'documenti';
            }
        }

        // ===== 12.3.2.5.5 Modifica allegato (?edit_document=ID) =====
        $editedDocument = $this->findDocument($practice, $request->query->getInt('edit_document'));
        $formDocumentEdit = $formFactory->createNamed('practice_document_edit', DocumentEditType::class, $editedDocument ?? new Document());
        if ($canEditDocuments && $editedDocument !== null) {
            $formDocumentEdit->handleRequest($request);
            if ($formDocumentEdit->isSubmitted() && $formDocumentEdit->isValid()) {
                $editedDocument->setName(trim($editedDocument->getName()))
                    ->setAgentNote($this->nullIfBlank($editedDocument->getAgentNote()));
                $em->flush();
                $this->addFlash('notice_success', 'Allegato aggiornato.');

                return $this->backToDocuments($practice);
            }
            if ($formDocumentEdit->isSubmitted()) {
                $em->refresh($editedDocument);
                $openTab = 'documenti';
            }
        }

        // ===== 12.3.2.7 Notifica di aggiornamento file =====
        $recipients = $notifier->recipientsFor($practice);
        $notify = new PracticeNotify('I documenti della pratica ' . $practice->getNumber() . ' sono stati aggiornati.');
        $formNotify = $formFactory->createNamed('practice_notify', PracticeNotifyType::class, $notify, ['recipients' => $recipients]);
        if ($canEdit) {
            $formNotify->handleRequest($request);
            if ($formNotify->isSubmitted() && $formNotify->isValid()) {
                $to = (string) $notify->getRecipient();
                $sent = $notifier->notifyFileUpdate($practice, $to, trim($notify->getMessage()), $this->staffName());
                $this->addFlash(
                    $sent ? 'notice_success' : 'notice_warning',
                    $sent
                        ? 'Notifica inviata a ' . $to . '.'
                        : 'Notifica non inviata: il trasporto email ha rifiutato il messaggio.'
                );

                return $this->backToDocuments($practice);
            }
            if ($formNotify->isSubmitted()) {
                $openTab = 'documenti';
            }
        }

        // ===== 12.3.4.2 Nuovo avviso =====
        $alert = new PracticeAlert();
        $formAlertNew = $formFactory->createNamed('practice_alert_new', PracticeAlertType::class, $alert);
        if ($canEdit) {
            $formAlertNew->handleRequest($request);
            if ($formAlertNew->isSubmitted() && $formAlertNew->isValid()) {
                $alert->setMessage(trim($alert->getMessage()));
                $practice->addAlert($alert);
                $em->getRepository(PracticeAlert::class)->add($alert);
                $this->addFlash('notice_success', 'Avviso creato.');

                return $this->backToAlerts($practice);
            }
            if ($formAlertNew->isSubmitted()) {
                $openTab = 'avvisi';
            }
        }

        // ===== 12.3.4.1.1 Modifica avviso (?edit_alert=ID) =====
        $editedAlert = $this->findAlert($practice, $request->query->getInt('edit_alert'));
        $formAlertEdit = $formFactory->createNamed('practice_alert_edit', PracticeAlertType::class, $editedAlert ?? new PracticeAlert());
        if ($canEdit && $editedAlert !== null) {
            $formAlertEdit->handleRequest($request);
            if ($formAlertEdit->isSubmitted() && $formAlertEdit->isValid()) {
                $editedAlert->setMessage(trim($editedAlert->getMessage()));
                $em->flush();
                $this->addFlash('notice_success', 'Avviso aggiornato.');

                return $this->backToAlerts($practice);
            }
            if ($formAlertEdit->isSubmitted()) {
                $em->refresh($editedAlert);
                $openTab = 'avvisi';
            }
        }

        return $this->render('role/agency/practice/sheet.html.twig', [
            'practice' => $practice,
            'notaries' => $this->notaries(),
            'geo' => $this->geoView($request, $geo, $practice),
            'formEdit' => $formEdit->createView(),
            'formDocumentNew' => $formDocumentNew->createView(),
            'formDocumentEdit' => $formDocumentEdit->createView(),
            'formNotify' => $formNotify->createView(),
            'formAlertNew' => $formAlertNew->createView(),
            'formAlertEdit' => $formAlertEdit->createView(),
            'openTab' => $openTab,
            // 12.3.3 Solo i non-amministratori vanno abilitati: gli admin entrano comunque.
            'staffMembers' => array_values(array_filter($this->users->findStaff($this->company(), true), static fn (User $u) => !$u->isAdmin())),
            'assignedStaffIds' => $practice->getStaff()->map(fn (User $u) => $u->getId())->toArray(),
            // 12.3.2.7 Destinatari possibili della notifica.
            'recipients' => $recipients,
            // 12.3.2.1 Tipi di documento previsti ma senza riga: si propone di sincronizzare.
            'missingDocuments' => count($this->documentSync->missingFor($em, $practice)),
        ]);
    }

    // ===================== 12.3.2 GESTIONE DOCUMENTALE =====================

    /**
     * 12.3.2.1 Sincronizza le righe documentali col catalogo (13.1): aggiunge quelle dei
     * tipi entrati dopo l'apertura della pratica. Le righe esistenti non si toccano,
     * quindi stato, allegati e mostra/nascondi restano dove sono.
     */
    #[Route('/{id}/documenti/sincronizza', name: 'agency_practice_documents_sync', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'practices')"))]
    public function agencyPracticeDocumentsSync(int $id, Request $request): RedirectResponse
    {
        $practice = $this->requirePracticeWithEditableDocuments($id);
        if (!$this->isCsrfTokenValid('practiceDocumentsSync', (string) $request->request->get('_csrf_token'))) {
            return $this->backToDocuments($practice);
        }

        $em = $this->em();
        $added = $this->documentSync->sync($em, $practice);
        $em->flush();

        $this->addFlash(
            $added > 0 ? 'notice_success' : 'notice_warning',
            $added > 0
                ? sprintf('Aggiunt%s %d document%s previst%s dalle tipologie documento.', $added === 1 ? 'o' : 'i', $added, $added === 1 ? 'o' : 'i', $added === 1 ? 'o' : 'i')
                : 'I documenti della pratica sono già allineati alle tipologie documento.'
        );

        return $this->backToDocuments($practice);
    }

    /** 12.3.2.5.6 Elimina allegato (record + file). */
    #[Route('/{id}/allegati/{did}/elimina', name: 'agency_practice_document_delete', methods: ['POST'], requirements: ['id' => '\d+', 'did' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'practices')"))]
    public function agencyPracticeDocumentDelete(int $id, int $did, Request $request, DocumentStorageService $storage): RedirectResponse
    {
        $this->requirePracticeWithEditableDocuments($id);
        [$practice, $document] = $this->requireDocument($id, $did);
        if ($this->isCsrfTokenValid('delete', (string) $request->request->get('_csrf_token'))) {
            $storage->delete($this->company(), $document->getStoragePath());
            // Se la riga resta senza allegati torna "da caricare": non c'è più nulla da verificare.
            $row = $document->getPracticeDocument();
            $row?->removeDocument($document)->resetStatusIfEmpty();
            $this->em()->getRepository(Document::class)->remove($document);
            $this->addFlash('notice_success', 'Allegato eliminato.');
        }

        return $this->backToDocuments($practice);
    }

    /** 12.3.2.5.1 Scarica un allegato. */
    #[Route('/{id}/allegati/{did}/scarica', name: 'agency_practice_document_download', methods: ['GET'], requirements: ['id' => '\d+', 'did' => '\d+'])]
    public function agencyPracticeDocumentDownload(int $id, int $did, DocumentStorageService $storage): Response
    {
        [, $document] = $this->requireDocument($id, $did);

        $absolute = $storage->absolutePath($this->company(), (string) $document->getStoragePath());
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
    #[Route('/{id}/allegati/{did}/anteprima', name: 'agency_practice_document_preview', methods: ['GET'], requirements: ['id' => '\d+', 'did' => '\d+'])]
    public function agencyPracticeDocumentPreview(int $id, int $did, DocumentStorageService $storage): Response
    {
        [, $document] = $this->requireDocument($id, $did);

        $absolute = $storage->absolutePath($this->company(), (string) $document->getStoragePath());
        if (!$document->hasFile() || !is_file($absolute)) {
            throw $this->createNotFoundException('File non presente sul server.');
        }

        $response = new BinaryFileResponse($absolute);
        $response->headers->set('Content-Type', $document->getMimeType() ?: DocumentStorageService::ALLOWED_MIME);
        $response->setContentDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            $document->getOriginalFilename() ?: ($document->getName() . '.pdf')
        );

        return $response;
    }

    // ===================== 12.3.3 STAFF SULLA PRATICA =====================

    /** 12.3.3.1 Seleziona chi accede alla pratica oltre agli amministratori. */
    #[Route('/{id}/staff', name: 'agency_practice_staff', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'practices')"))]
    public function agencyPracticeStaff(int $id, Request $request): RedirectResponse
    {
        $practice = $this->requireEditablePractice($id);
        if (!$this->isCsrfTokenValid('practiceStaff', (string) $request->request->get('_csrf_token'))) {
            return $this->backToTab($practice, 'staff');
        }

        $em = $this->em();
        $practice->clearStaff();
        foreach (array_map('intval', (array) $request->request->all('staff')) as $userId) {
            $member = $userId > 0 ? $this->users->find($userId) : null;
            // Solo staff della stessa agenzia; gli amministratori hanno già accesso.
            if ($member !== null && $member->isAgencyUser() && $member->getCompany() === $this->company() && !$member->isAdmin()) {
                $practice->addStaff($member);
            }
        }
        $em->flush();
        $this->addFlash('notice_success', 'Accessi alla pratica aggiornati.');

        return $this->backToTab($practice, 'staff');
    }

    // ===================== 12.3.4 AVVISI =====================

    /** 12.3.4.1.2 Elimina avviso. */
    #[Route('/{id}/avvisi/{aid}/elimina', name: 'agency_practice_alert_delete', methods: ['POST'], requirements: ['id' => '\d+', 'aid' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'practices')"))]
    public function agencyPracticeAlertDelete(int $id, int $aid, Request $request): RedirectResponse
    {
        [$practice, $alert] = $this->requireAlert($id, $aid);
        if ($this->isCsrfTokenValid('delete', (string) $request->request->get('_csrf_token'))) {
            $this->em()->getRepository(PracticeAlert::class)->remove($alert);
            $this->addFlash('notice_success', 'Avviso eliminato.');
        }

        return $this->backToAlerts($practice);
    }

    // ===================== 12.3.5 / 12.3.6 ARCHIVIAZIONE =====================

    /** 12.3.5 Archivia la pratica, solo col via libera del notaio (12.3.5.2). */
    #[Route('/{id}/archivia', name: 'agency_practice_archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'practices')"))]
    public function agencyPracticeArchive(int $id, Request $request): RedirectResponse
    {
        $practice = $this->requirePractice($id);
        if (!$this->isCsrfTokenValid('practiceArchive', (string) $request->request->get('_csrf_token'))) {
            return $this->redirectToRoute('agency_practice_sheet', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        if (!$practice->getCanArchive()) {
            $this->addFlash('notice_danger', $practice->isArchived()
                ? 'La pratica è già archiviata.'
                : 'Il notaio non ha ancora completato la pratica: non puoi archiviarla.');

            return $this->redirectToRoute('agency_practice_sheet', ['id' => $id], Response::HTTP_SEE_OTHER);
        }

        $practice->setStatus(Practice::STATUS_ARCHIVED);
        $this->em()->flush();
        $this->addFlash('notice_success', 'Pratica ' . $practice->getNumber() . ' archiviata. Scarica l\'archivio per conservarne una copia.');

        return $this->redirectToRoute('agency_practice_sheet', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    /**
     * Scarica documenti: tutti gli allegati della pratica in uno ZIP, una cartella per tipologia.
     * (L'archivio della pratica archiviata starà sul server Aruba: collegamento ancora da sviluppare.)
     */
    #[Route('/{id}/documenti/scarica', name: 'agency_practice_documents_download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function agencyPracticeDocumentsDownload(int $id, DocumentStorageService $storage): Response
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
                $absolute = $storage->absolutePath($this->company(), (string) $document->getStoragePath());
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

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManager();

        return $em;
    }

    /** Agenzia dell'utente collegato (proprietaria delle pratiche e dei file). */
    private function company(): Company
    {
        return $this->companyService->getCurrentCompany()
            ?? throw $this->createAccessDeniedException('Contesto agenzia non disponibile.');
    }

    private function staffName(): string
    {
        $user = $this->getUser();

        return $user instanceof User ? $user->getFullName() : 'Agenzia';
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * 12.3.2.6 Salva il file del nuovo allegato e collega il Document alla riga. Il file non
     * conserva il nome originale: prende il "nome allegato" del form. Se il salvataggio su
     * disco non riesce l'errore va sul campo file e il Document non resta a database.
     */
    private function storeUpload(DocumentStorageService $storage, Practice $practice, PracticeDocument $row, Document $document, PracticeDocumentUpload $upload, FormInterface $form): bool
    {
        $em = $this->em();
        $row->addDocument($document);
        $em->persist($document);

        try {
            $meta = $storage->store($this->company(), $practice, $upload->getFile(), $document->getName());
            $document->setOriginalFilename($meta['name'])
                ->setStoragePath($meta['path'])
                ->setMimeType($meta['mime'])
                ->setSizeBytes($meta['size']);

            // Caricare il primo file porta la riga da "da caricare" a "da verificare notaio"; un nuovo
            // file su una riga che il notaio aveva rimandato all'agenzia la riporta a lui.
            if (in_array($row->getStatus(), [PracticeDocument::STATUS_TO_UPLOAD, PracticeDocument::STATUS_TO_VERIFY_AGENCY], true)) {
                $row->setStatus(PracticeDocument::STATUS_TO_VERIFY);
            }
            $em->flush();

            return true;
        } catch (\Throwable $e) {
            $row->removeDocument($document);
            $em->detach($document);
            $form->get('file')->addError(new FormError('Caricamento non riuscito: ' . $e->getMessage()));

            return false;
        }
    }

    /**
     * 12.3.3 Oltre a esistere, la pratica dev'essere accessibile all'utente: gli
     * amministratori vedono tutto, gli altri solo quelle in cui sono inseriti.
     */
    private function requirePractice(int $id): Practice
    {
        $practice = $this->em()->getRepository(Practice::class)->find($id);
        if ($practice === null || $practice->getCompany() !== $this->company()) {
            throw $this->createNotFoundException('Pratica non trovata.');
        }

        $user = $this->getUser();
        if (!$user instanceof User || !$practice->isAccessibleBy($user)) {
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

    /** Allegato della pratica, per id; null se non le appartiene. */
    private function findDocument(Practice $practice, int $did): ?Document
    {
        if ($did <= 0) {
            return null;
        }
        foreach ($practice->getPracticeDocuments() as $row) {
            foreach ($row->getDocuments() as $document) {
                if ((int) $document->getId() === $did) {
                    return $document;
                }
            }
        }

        return null;
    }

    /** Avviso dell'agenzia sulla pratica, per id; null se non le appartiene o è del notaio (lo gestisce lui). */
    private function findAlert(Practice $practice, int $aid): ?PracticeAlert
    {
        if ($aid <= 0) {
            return null;
        }
        foreach ($practice->getAlerts() as $alert) {
            if ((int) $alert->getId() === $aid && !$alert->isByNotary()) {
                return $alert;
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
        $document = $this->findDocument($practice, $did);
        if ($document === null) {
            throw $this->createNotFoundException('Allegato non trovato in questa pratica.');
        }

        return [$practice, $document];
    }

    /**
     * @return array{0: Practice, 1: PracticeAlert}
     */
    private function requireAlert(int $id, int $aid): array
    {
        $practice = $this->requireEditablePractice($id);
        $alert = $this->findAlert($practice, $aid);
        if ($alert === null) {
            throw $this->createNotFoundException('Avviso non trovato in questa pratica.');
        }

        return [$practice, $alert];
    }

    private function backToDocuments(Practice $practice): RedirectResponse
    {
        return $this->backToTab($practice, 'documenti');
    }

    /**
     * Torna alla scheda sulla tab da cui si è partiti: la tab va in sessione (chiave "{rotta}_tab",
     * la ripristina components/js/tab_session_js), così l'URL resta pulito.
     */
    private function backToTab(Practice $practice, string $tab): RedirectResponse
    {
        $this->container->get('request_stack')->getSession()->set('agency_practice_sheet_tab', $tab);

        return $this->redirectToRoute('agency_practice_sheet', ['id' => $practice->getId()], Response::HTTP_SEE_OTHER);
    }

    private function backToAlerts(Practice $practice): RedirectResponse
    {
        return $this->backToTab($practice, 'avvisi');
    }

    /**
     * 12.2.5 Città e CAP dal picker geografico, entrambi obbligatori.
     * Il picker sta fuori dal form Symfony (city_id / zip_id): qui si risolvono e, se mancano
     * o non tornano, l'errore va sul form (il picker si colora, vedi geoView()).
     *
     * @return array{city: City|null, zip: Zip|null, error: string}
     */
    private function resolveGeo(Request $request, FormInterface $form): array
    {
        $em = $this->em();

        $cityId = (int) $request->request->get('city_id');
        $city = $cityId > 0 ? $em->getRepository(City::class)->find($cityId) : null;
        $zipId = (int) $request->request->get('zip_id');
        $zip = $zipId > 0 ? $em->getRepository(Zip::class)->find($zipId) : null;

        $error = '';
        if ($city === null) {
            $error = 'Seleziona la città dell\'immobile';
        } elseif ($zip === null || !$zip->getCities()->contains($city)) {
            // Il CAP deve essere fra quelli del comune scelto (relazione molti-a-molti).
            $error = 'Seleziona un CAP valido per il comune scelto';
        }
        if ($error !== '') {
            $form->addError(new FormError($error));
        }

        return ['city' => $city, 'zip' => $zip, 'error' => $error];
    }

    /**
     * Valori del picker città/CAP da rimettere nei campi: quelli appena inviati se il form
     * è tornato indietro ($geo da resolveGeo()), altrimenti quelli della pratica.
     * `error` colora il picker.
     *
     * @param array{city: City|null, zip: Zip|null, error: string}|null $geo
     *
     * @return array{cityId: int|string, cityName: string, zipId: int|string, error: string}
     */
    private function geoView(Request $request, ?array $geo, ?Practice $practice): array
    {
        if ($geo !== null) {
            return [
                'cityId' => $geo['city']?->getId() ?? '',
                'cityName' => (string) $geo['city']?->getName(),
                'zipId' => (int) $request->request->get('zip_id') ?: '',
                'error' => $geo['error'],
            ];
        }

        return [
            'cityId' => $practice?->getCityRefId() ?? '',
            'cityName' => (string) $practice?->getCity(),
            'zipId' => $practice?->getZipRefId() ?? '',
            'error' => '',
        ];
    }

    /**
     * 12.2.9 Notaio assegnato: vale solo se è uno dei notai attivi, così un valore arrivato
     * a mano non finisce in pratica (ed è quello che dà l'accesso, vedi 17.1).
     */
    private function checkNotary(FormInterface $form): void
    {
        $email = mb_strtolower(trim((string) $form->get('notaryEmail')->getData()));
        if ($email !== '' && $this->findNotary($email) === null) {
            $form->get('notaryEmail')->addError(new FormError('Il notaio selezionato non è più disponibile'));
        }
    }

    /**
     * Dopo la validazione: notaio dall'e-mail scelta nell'autocomplete (vuoto = nessun notaio),
     * oggetto vuoto = null, città e CAP dal picker.
     *
     * @param array{city: City, zip: Zip, error: string} $geo
     */
    private function completePractice(Practice $practice, array $geo, FormInterface $form): void
    {
        ['city' => $city, 'zip' => $zip] = $geo;
        $practice->setNotary($this->findNotary(mb_strtolower(trim((string) $form->get('notaryEmail')->getData()))))
            ->setAddress(trim((string) $practice->getAddress()))
            ->setSubject($this->nullIfBlank($practice->getSubject()))
            ->setCity($city->getName())->setCityRefId((int) $city->getId())
            ->setZip($zip->getCode())->setZipRefId((int) $zip->getId());
    }

    /** Etichette degli autocomplete (parti e notaio) per il form di modifica. */
    private function presetPracticeLabels(FormInterface $form, Practice $practice): void
    {
        $form->get('sellerLabel')->setData($practice->getSeller() ? $this->customerLabel($practice->getSeller()) : '');
        $form->get('buyerLabel')->setData($practice->getBuyer() ? $this->customerLabel($practice->getBuyer()) : '');
        $notary = $practice->getNotary();
        $form->get('notaryEmail')->setData($notary?->getEmail() ?? '');
        $form->get('notaryLabel')->setData($notary !== null ? $notary->getFullName() . ' (' . $notary->getEmail() . ')' : '');
    }

    /** Etichetta del cliente come la mostra l'autocomplete. */
    private function customerLabel(Customer $customer): string
    {
        return $customer->getFullName() . ($customer->getFiscalCode() ? ' (' . $customer->getFiscalCode() . ')' : '');
    }

    /**
     * 12.2.9 Notai assegnabili: utenti attivi con ROLE_NOTARY.
     *
     * @return User[]
     */
    private function notaries(): array
    {
        return $this->users->findActiveNotaries();
    }

    /** Notaio assegnabile con questa e-mail (in minuscolo), null se non c'è. */
    private function findNotary(string $email): ?User
    {
        if ($email === '') {
            return null;
        }

        foreach ($this->notaries() as $notary) {
            if (mb_strtolower((string) $notary->getEmail()) === $email) {
                return $notary;
            }
        }

        return null;
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
}
