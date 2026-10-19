<?php

namespace App\Controller\Notary;

use App\Entity\Master\Company;
use App\Entity\Slave\Document;
use App\Entity\Slave\Practice;
use App\Entity\Slave\PracticeAlert;
use App\Entity\Slave\PracticeDocument;
use App\Form\DocumentNotaryNoteType;
use App\Form\Model\PracticeDocumentUpload;
use App\Form\Model\PracticeNotify;
use App\Form\PracticeAlertType;
use App\Form\PracticeDocumentUploadType;
use App\Form\PracticeNotifyType;
use App\Repository\Slave\PracticeAlertRepository;
use App\Repository\Slave\PracticeDocumentRepository;
use App\Repository\Slave\PracticeRepository;
use App\Service\CompanyService;
use App\Service\DocumentStorageService;
use App\Service\FilterService;
use App\Service\NotaryAgencyAccessService;
use App\Service\PracticeNotifierService;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * 17. Area staff notaio. Il notaio è un utente master; seleziona un'agenzia
 * (Company) che ripunta lo slave sul suo DB e opera sulle pratiche di quel tenant.
 */
#[Route('/notaio')]
#[IsGranted('ROLE_NOTARY')]
class NotaryController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly CompanyService $companyService,
        private readonly NotaryAgencyAccessService $agencyAccess,
    ) {
    }

    /** 17.1 Selezione agenzia (solo quelle abbinate al notaio); scelta l'agenzia si va alla sua scrivania (notary_desk). */
    #[Route('', name: 'notary_index', methods: ['GET'])]
    public function notaryIndex(): Response
    {
        return $this->render('role/notary/index.html.twig', [
            'companies' => $this->allowedCompanies(),
            'current' => $this->companyService->getCurrentCompany(),
        ]);
    }

    /** 17.1 Seleziona agenzia: ripunta lo slave e prosegue alle pratiche. */
    #[Route('/seleziona-agenzia', name: 'notary_select_agency', methods: ['POST'])]
    public function notarySelectAgency(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('notarySelectAgency', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('notice_danger', 'Token non valido, riprova.');

            return $this->redirectToRoute('notary_index');
        }

        $id = $request->request->get('company_id');
        $company = $id ? $this->registry->getManager('master')->getRepository(Company::class)->find($id) : null;
        // Deve essere attiva E tra le agenzie abbinate al notaio.
        if ($company === null || !$this->agencyAccess->hasAccess($this->getUser(), $company)) {
            $this->addFlash('notice_danger', 'Agenzia non valida o non autorizzata.');

            return $this->redirectToRoute('notary_index');
        }

        $this->companyService->switchToCompany($company);

        return $this->redirectToRoute('notary_desk');
    }

    /**
     * Scrivania dell'agenzia scelta (come quella dell'agenzia, per il notaio): le sezioni, gli
     * avvisi e i documenti da verificare delle pratiche assegnate al notaio.
     */
    #[Route('/scrivania', name: 'notary_desk', methods: ['GET'])]
    public function notaryDesk(): Response
    {
        $company = $this->currentAllowedCompany();
        if ($company === null) {
            return $this->redirectToRoute('notary_index');
        }

        $em = $this->registry->getManager('slave');
        $email = $this->getUser()->getUserIdentifier();
        /** @var PracticeAlertRepository $alerts */
        $alerts = $em->getRepository(PracticeAlert::class);
        /** @var PracticeDocumentRepository $rows */
        $rows = $em->getRepository(PracticeDocument::class);

        return $this->render('role/notary/desk.html.twig', [
            'company' => $company,
            'alerts' => $alerts->findForNotary($email),
            'toVerify' => $rows->findToVerifyForNotary($email),
        ]);
    }

    /** Deseleziona l'agenzia corrente. */
    #[Route('/cambia-agenzia', name: 'notary_deselect_agency', methods: ['GET'])]
    public function notaryDeselectAgency(): RedirectResponse
    {
        $this->companyService->clearSession();

        return $this->redirectToRoute('notary_index');
    }

    /** 17.1.1 Gestione pratiche: 17.1.1.1 elenco pratiche accessibili, a schede per stato (vedi notaryPracticeTab). */
    #[Route('/pratiche/lista', name: 'notary_practices', methods: ['GET'])]
    public function notaryPractices(Request $request, PaginatorInterface $paginator): Response
    {
        $company = $this->currentAllowedCompany();
        if ($company === null) {
            $this->addFlash('notice_danger', 'Seleziona prima un\'agenzia.');

            return $this->redirectToRoute('notary_index');
        }

        // 17.1 L'accesso all'agenzia è già stato verificato (una pratica assegnata al
        // notaio in questo slave): dentro l'agenzia vede tutte le sue pratiche.
        $filters = FilterService::notaryPracticeFilter($request);
        /** @var PracticeRepository $practices */
        $practices = $this->registry->getManager('slave')->getRepository(Practice::class);
        $records = $paginator->paginate($practices->queryNotaryFiltered($filters), FilterService::page($request, 'notary_practices'), 20);

        return $this->render('role/notary/practice/list.html.twig', [
            'company' => $company,
            'records' => $records,
            'filters' => $filters,
            'statuses' => Practice::STATUSES,
            // Contatori delle schede: con gli stessi filtri, stato escluso
            'statusCounts' => $practices->countNotaryByStatus($filters),
        ]);
    }

    /**
     * Cambio scheda dell'elenco pratiche: lo stato scelto va in sessione (non nell'URL) e
     * l'elenco riparte dalla prima pagina, con gli altri filtri invariati.
     */
    #[Route('/pratiche/stato/{status}', name: 'notary_practice_tab', methods: ['GET'], requirements: ['status' => 'open|completed|archived'])]
    public function notaryPracticeTab(string $status, Request $request): RedirectResponse
    {
        $request->getSession()->set(FilterService::NOTARY_PRACTICE_TAB, $status);
        $request->getSession()->remove('notary_practices_page');

        return $this->redirectToRoute('notary_practices');
    }

    /**
     * 17.1.1.1.5 Scheda pratica: dati, parti, elenco documenti, con i form dei modali
     * (CONVENZIONI 6): nuovo allegato (17.1.1.1.5.3), note del notaio su un allegato
     * (17.1.1.1.5.2.6, ?edit_document=ID) e notifica di aggiornamento file (12.3.2.7, come
     * nell'area agenzia). I form si inviano a questa pagina, che salva e torna
     * alla scheda; se non sono validi la scheda si ripresenta col modale aperto.
     */
    #[Route('/pratiche/scheda/{id}', name: 'notary_practice_sheet', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function notaryPracticeSheet(int $id, Request $request, DocumentStorageService $storage, FormFactoryInterface $formFactory, PracticeNotifierService $notifier): Response
    {
        [$company, $practice] = $this->requirePractice($id);
        if ($company === null) {
            return $this->redirectToRoute('notary_index');
        }
        if ($practice === null) {
            throw $this->createNotFoundException('Pratica non trovata.');
        }
        // 12.3.2 A documenti in sola lettura i form dei documenti non si gestiscono: la POST si ferma
        // qui. Gli avvisi del notaio no (sono promemoria): si fermano solo a pratica archiviata.
        $isAlertPost = $request->request->has('notary_alert_new') || $request->request->has('notary_alert_edit');
        if ($request->isMethod('POST') && ($isAlertPost ? $practice->isArchived() : $this->documentsLocked($practice))) {
            return $this->redirectToRoute('notary_practice_sheet', ['id' => $id]);
        }
        $em = $this->registry->getManager('slave');

        // ===== Avvisi del notaio: nuovo e modifica (?edit_alert=ID, solo i suoi) =====
        $alertNew = (new PracticeAlert())->setByNotary(true);
        $formAlertNew = $formFactory->createNamed('notary_alert_new', PracticeAlertType::class, $alertNew, ['for_notary' => true]);
        $formAlertNew->handleRequest($request);
        if ($formAlertNew->isSubmitted() && $formAlertNew->isValid()) {
            $alertNew->setMessage(trim($alertNew->getMessage()));
            $practice->addAlert($alertNew);
            $em->persist($alertNew);
            $em->flush();
            $this->addFlash('notice_success', 'Avviso creato.');

            return $this->backToAlerts($request, $id);
        }

        $editedAlert = $this->findNotaryAlert($practice, $request->query->getInt('edit_alert'));
        $formAlertEdit = $formFactory->createNamed('notary_alert_edit', PracticeAlertType::class, $editedAlert ?? (new PracticeAlert())->setByNotary(true), ['for_notary' => true]);
        if ($editedAlert !== null) {
            $formAlertEdit->handleRequest($request);
            if ($formAlertEdit->isSubmitted() && $formAlertEdit->isValid()) {
                $editedAlert->setMessage(trim($editedAlert->getMessage()));
                $em->flush();
                $this->addFlash('notice_success', 'Avviso aggiornato.');

                return $this->backToAlerts($request, $id);
            }
            if ($formAlertEdit->isSubmitted()) {
                $em->refresh($editedAlert);
            }
        }

        // ===== 17.1.1.1.5.3 Nuovo allegato =====
        $upload = new PracticeDocumentUpload();
        $formDocumentNew = $formFactory->createNamed('practice_document_new', PracticeDocumentUploadType::class, $upload, [
            'rows' => $practice->getPracticeDocuments()->toArray(),
        ]);
        $formDocumentNew->handleRequest($request);
        if ($formDocumentNew->isSubmitted() && $formDocumentNew->isValid()) {
            $row = $upload->getPracticeDocument();
            $name = trim($upload->getName());
            $note = trim((string) $upload->getNote());
            $doc = (new Document())->setName($name)->setNotaryNote($note !== '' ? $note : null);
            $row->addDocument($doc);
            $em->persist($doc);

            try {
                // Il file non conserva il nome originale: prende il "nome allegato" del form.
                $meta = $storage->store((string) $company->getDbName(), $practice, $upload->getFile(), $name);
                $doc->setOriginalFilename($meta['name'])
                    ->setStoragePath($meta['path'])
                    ->setMimeType($meta['mime'])
                    ->setSizeBytes($meta['size']);
                if ($row->getStatus() === PracticeDocument::STATUS_TO_UPLOAD) {
                    $row->setStatus(PracticeDocument::STATUS_TO_VERIFY);
                }
                $em->flush();
                $this->addFlash('notice_success', 'Allegato caricato.');

                return $this->redirectToRoute('notary_practice_sheet', ['id' => $id]);
            } catch (\Throwable $e) {
                // Salvataggio su disco non riuscito: il Document non resta e l'errore va sul campo.
                $row->removeDocument($doc);
                $em->detach($doc);
                $formDocumentNew->get('file')->addError(new FormError('Caricamento non riuscito: ' . $e->getMessage()));
            }
        }

        // ===== 17.1.1.1.5.2.6 Note del notaio (?edit_document=ID) =====
        $editedDocument = $this->findDocument($practice, $request->query->getInt('edit_document'));
        $formDocumentNote = $formFactory->createNamed('practice_document_note', DocumentNotaryNoteType::class, $editedDocument ?? new Document());
        if ($editedDocument !== null) {
            $formDocumentNote->handleRequest($request);
            if ($formDocumentNote->isSubmitted() && $formDocumentNote->isValid()) {
                $note = trim((string) $editedDocument->getNotaryNote());
                $editedDocument->setNotaryNote($note !== '' ? $note : null);

                // "Da verificare agenzia" vale per la riga documentale: acceso la rimanda all'agenzia
                // (che, caricando un nuovo allegato, la riporta al notaio); spento torna al notaio.
                $row = $editedDocument->getPracticeDocument();
                $toAgency = (bool) $formDocumentNote->get('toVerifyAgency')->getData();
                if ($row !== null && $toAgency) {
                    $row->setStatus(PracticeDocument::STATUS_TO_VERIFY_AGENCY);
                } elseif ($row !== null && $row->getStatus() === PracticeDocument::STATUS_TO_VERIFY_AGENCY) {
                    $row->setStatus(PracticeDocument::STATUS_TO_VERIFY);
                }
                $em->flush();
                $this->addFlash('notice_success', $toAgency
                    ? 'Note aggiornate: «' . $row?->getLabel() . '» è da verificare per l\'agenzia.'
                    : 'Note aggiornate.');

                return $this->redirectToRoute('notary_practice_sheet', ['id' => $id]);
            }
            if ($formDocumentNote->isSubmitted()) {
                $em->refresh($editedDocument);
            }
        }

        // ===== 12.3.2.7 Notifica di aggiornamento file (come nell'area agenzia) =====
        // Destinatari della pratica (parti, staff abilitato), senza il notaio stesso.
        $self = mb_strtolower($this->getUser()->getUserIdentifier());
        $recipients = array_filter($notifier->recipientsFor($practice), static fn ($label, $email) => mb_strtolower((string) $email) !== $self, ARRAY_FILTER_USE_BOTH);
        $notify = new PracticeNotify('I documenti della pratica ' . $practice->getNumber() . ' sono stati aggiornati.');
        $formNotify = $formFactory->createNamed('practice_notify', PracticeNotifyType::class, $notify, ['recipients' => $recipients]);
        $formNotify->handleRequest($request);
        if ($formNotify->isSubmitted() && $formNotify->isValid()) {
            $to = (string) $notify->getRecipient();
            $sent = $notifier->notifyFileUpdate($practice, $to, trim($notify->getMessage()), $this->getUser()->getFullName());
            $this->addFlash(
                $sent ? 'notice_success' : 'notice_warning',
                $sent
                    ? 'Notifica inviata a ' . $to . '.'
                    : 'Notifica non inviata: il trasporto email ha rifiutato il messaggio.'
            );

            return $this->redirectToRoute('notary_practice_sheet', ['id' => $id]);
        }

        return $this->render('role/notary/practice/sheet.html.twig', [
            'company' => $company,
            'practice' => $practice,
            'formDocumentNew' => $formDocumentNew->createView(),
            'formDocumentNote' => $formDocumentNote->createView(),
            'formNotify' => $formNotify->createView(),
            'recipients' => $recipients,
            'formAlertNew' => $formAlertNew->createView(),
            'formAlertEdit' => $formAlertEdit->createView(),
        ]);
    }

    /** Elimina un avviso del notaio (quelli dell'agenzia non li tocca). */
    #[Route('/pratiche/{id}/avvisi/{aid}/elimina', name: 'notary_practice_alert_delete', requirements: ['id' => '\d+', 'aid' => '\d+'], methods: ['POST'])]
    public function notaryPracticeAlertDelete(int $id, int $aid, Request $request): RedirectResponse
    {
        [$company, $practice] = $this->requirePractice($id);
        if ($practice === null) {
            return $this->redirectToRoute($company ? 'notary_practice_sheet' : 'notary_index', $company ? ['id' => $id] : []);
        }
        $alert = $this->findNotaryAlert($practice, $aid);
        if ($alert === null) {
            throw $this->createNotFoundException('Avviso non trovato fra i tuoi avvisi di questa pratica.');
        }
        if (!$practice->isArchived() && $this->isCsrfTokenValid('notaryAlertDelete', (string) $request->request->get('_csrf_token'))) {
            $em = $this->registry->getManager('slave');
            $practice->getAlerts()->removeElement($alert);
            $em->remove($alert);
            $em->flush();
            $this->addFlash('notice_success', 'Avviso eliminato.');
        }

        return $this->backToAlerts($request, $id);
    }

    /** Torna alla scheda sulla tab "Avvisi": la tab va in sessione (tab_session), non nell'URL. */
    private function backToAlerts(Request $request, int $id): RedirectResponse
    {
        $request->getSession()->set('notary_practice_sheet_tab', 'avvisi');

        return $this->redirectToRoute('notary_practice_sheet', ['id' => $id]);
    }

    /** Avviso del notaio sulla pratica, per id; null se non c'è o è dell'agenzia. */
    private function findNotaryAlert(Practice $practice, int $aid): ?PracticeAlert
    {
        if ($aid <= 0) {
            return null;
        }
        foreach ($practice->getAlerts() as $alert) {
            if ((int) $alert->getId() === $aid && $alert->isByNotary()) {
                return $alert;
            }
        }

        return null;
    }

    /** 17.1.1.2 Cambio stato pratica: completata (che vale anche come via libera all'archiviazione) / riapri (aperta). */
    #[Route('/pratiche/{id}/stato', name: 'notary_practice_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function notaryPracticeStatus(int $id, Request $request): RedirectResponse
    {
        [$company, $practice] = $this->requirePractice($id);
        if ($practice === null) {
            return $this->redirectToRoute($company ? 'notary_practice_sheet' : 'notary_index', $company ? ['id' => $id] : []);
        }
        if (!$this->isCsrfTokenValid('practice_status_' . $practice->getId(), (string) $request->request->get('_csrf_token'))) {
            return $this->redirectToRoute('notary_practice_sheet', ['id' => $id]);
        }

        $target = (string) $request->request->get('status', '');
        $allowed = [Practice::STATUS_OPEN, Practice::STATUS_COMPLETED];
        if (!in_array($target, $allowed, true)) {
            $this->addFlash('notice_danger', 'Stato non valido.');

            return $this->redirectToRoute('notary_practice_sheet', ['id' => $id]);
        }

        // 17.1.1 "Completata" solo a documenti richiesti tutti verificati.
        if ($target === Practice::STATUS_COMPLETED && !$practice->getCanComplete()) {
            $pending = $practice->getDocumentsPendingVerification();
            $labels = array_map(static fn ($row) => $row->getLabel(), $pending->toArray());
            $this->addFlash('notice_danger', sprintf(
                'Non puoi completare la pratica: %d document%s da verificare (%s).',
                count($labels),
                count($labels) === 1 ? 'o' : 'i',
                implode(', ', $labels)
            ));

            return $this->redirectToRoute('notary_practice_sheet', ['id' => $id]);
        }

        $practice->setStatus($target);
        $this->registry->getManager('slave')->flush();
        $this->addFlash('notice_success', 'Stato pratica aggiornato: ' . $practice->getStatusLabel() . '.');

        return $this->redirectToRoute('notary_practice_sheet', ['id' => $id]);
    }

    /** 17.1.1.1.5.2.3 Scarica file allegato. */
    #[Route('/pratiche/{id}/documenti/{did}/scarica', name: 'notary_practice_document_download', requirements: ['id' => '\d+', 'did' => '\d+'], methods: ['GET'])]
    public function notaryPracticeDocumentDownload(int $id, int $did, DocumentStorageService $storage): Response
    {
        [$company, $practice, $doc] = $this->requireDocument($id, $did);
        if ($doc === null) {
            if ($company === null) {
                return $this->redirectToRoute('notary_index');
            }
            throw $this->createNotFoundException('Documento non trovato.');
        }
        if (!$doc->hasFile()) {
            $this->addFlash('notice_danger', 'Nessun file caricato per questo documento.');

            return $this->redirectToRoute('notary_practice_sheet', ['id' => $id]);
        }

        $abs = $storage->absolutePath((string) $company->getDbName(), (string) $doc->getStoragePath());
        if (!is_file($abs)) {
            $this->addFlash('notice_danger', 'File non presente sul server.');

            return $this->redirectToRoute('notary_practice_sheet', ['id' => $id]);
        }

        $response = new BinaryFileResponse($abs);
        $response->setContentDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $doc->getOriginalFilename() ?: ($doc->getName() . '.dat')
        );

        return $response;
    }

    /**
     * Anteprima dell'allegato: lo stesso file servito inline, per l'iframe del modale.
     * Gli allegati sono solo PDF, quindi il browser lo mostra col suo viewer.
     */
    #[Route('/pratiche/{id}/documenti/{did}/anteprima', name: 'notary_practice_document_preview', requirements: ['id' => '\d+', 'did' => '\d+'], methods: ['GET'])]
    public function notaryPracticeDocumentPreview(int $id, int $did, DocumentStorageService $storage): Response
    {
        [$company, $practice, $doc] = $this->requireDocument($id, $did);
        if ($doc === null || $company === null) {
            throw $this->createNotFoundException('Documento non trovato.');
        }
        if (!$doc->hasFile()) {
            throw $this->createNotFoundException('Nessun file caricato per questo documento.');
        }

        $abs = $storage->absolutePath((string) $company->getDbName(), (string) $doc->getStoragePath());
        if (!is_file($abs)) {
            throw $this->createNotFoundException('File non presente sul server.');
        }

        $response = new BinaryFileResponse($abs);
        $response->headers->set('Content-Type', $doc->getMimeType() ?: DocumentStorageService::ALLOWED_MIME);
        $response->setContentDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            $doc->getOriginalFilename() ?: ($doc->getName() . '.pdf')
        );

        return $response;
    }

    /**
     * 17.1.1.1.5.2.4 Richiesto / non richiesto: dal punto 12 è la riga documentale
     * (il tipo previsto per la pratica) a essere richiesta, non il singolo allegato.
     */
    #[Route('/pratiche/{id}/righe/{rid}/richiesto', name: 'notary_practice_row_toggle_visible', requirements: ['id' => '\d+', 'rid' => '\d+'], methods: ['POST'])]
    public function notaryPracticeRowToggleVisible(int $id, int $rid, Request $request): RedirectResponse
    {
        [$company, $practice, $row] = $this->requireRow($id, $rid);
        if ($row === null) {
            return $this->redirectToRoute($company ? 'notary_practice_sheet' : 'notary_index', $company ? ['id' => $id] : []);
        }
        if ($this->documentsLocked($practice)) {
            return $this->redirectToRoute('notary_practice_sheet', ['id' => $id]);
        }
        if ($this->isCsrfTokenValid('practice_doc_' . $practice->getId(), (string) $request->request->get('_csrf_token'))) {
            $row->setVisible(!$row->isVisible());
            $this->registry->getManager('slave')->flush();
        }

        return $this->redirectToRoute('notary_practice_sheet', ['id' => $id]);
    }

    /** 17.1.1.1.5.2.5 Modifica stato della riga documentale: Da verificare / Verificato. */
    #[Route('/pratiche/{id}/righe/{rid}/stato', name: 'notary_practice_row_status', requirements: ['id' => '\d+', 'rid' => '\d+'], methods: ['POST'])]
    public function notaryPracticeRowStatus(int $id, int $rid, Request $request): RedirectResponse
    {
        [$company, $practice, $row] = $this->requireRow($id, $rid);
        if ($row === null) {
            return $this->redirectToRoute($company ? 'notary_practice_sheet' : 'notary_index', $company ? ['id' => $id] : []);
        }
        if ($this->documentsLocked($practice)) {
            return $this->redirectToRoute('notary_practice_sheet', ['id' => $id]);
        }
        if ($this->isCsrfTokenValid('practice_doc_' . $practice->getId(), (string) $request->request->get('_csrf_token'))) {
            // Senza allegato non c'è nulla da verificare: lo stato non si tocca. Resta
            // possibile riaprire una riga già verificata (es. dopo aver eliminato il file).
            if (!$row->hasFiles() && $row->getStatus() !== PracticeDocument::STATUS_VERIFIED) {
                $this->addFlash('notice_danger', 'Nessun allegato caricato per «' . $row->getLabel() . '»: lo stato non è modificabile.');

                return $this->redirectToRoute('notary_practice_sheet', ['id' => $id]);
            }

            $row->setStatus(
                $row->getStatus() === PracticeDocument::STATUS_VERIFIED
                    ? PracticeDocument::STATUS_TO_VERIFY
                    : PracticeDocument::STATUS_VERIFIED
            );
            $this->registry->getManager('slave')->flush();
        }

        return $this->redirectToRoute('notary_practice_sheet', ['id' => $id]);
    }

    /** Elimina un allegato (record + file). */
    #[Route('/pratiche/{id}/documenti/{did}/elimina', name: 'notary_practice_document_delete', requirements: ['id' => '\d+', 'did' => '\d+'], methods: ['POST'])]
    public function notaryPracticeDocumentDelete(int $id, int $did, Request $request, DocumentStorageService $storage): RedirectResponse
    {
        [$company, $practice, $doc] = $this->requireDocument($id, $did);
        if ($doc === null) {
            return $this->redirectToRoute($company ? 'notary_practice_sheet' : 'notary_index', $company ? ['id' => $id] : []);
        }
        if ($this->documentsLocked($practice)) {
            return $this->redirectToRoute('notary_practice_sheet', ['id' => $id]);
        }
        if ($this->isCsrfTokenValid('practice_doc_' . $practice->getId(), (string) $request->request->get('_csrf_token'))) {
            $storage->delete((string) $company->getDbName(), $doc->getStoragePath());
            $em = $this->registry->getManager('slave');
            // Se la riga resta senza allegati torna "da caricare": non c'è più nulla da verificare.
            $row = $doc->getPracticeDocument();
            $row?->removeDocument($doc)->resetStatusIfEmpty();
            $em->remove($doc);
            $em->flush();
            $this->addFlash('notice_success', 'Allegato eliminato.');
        }

        return $this->redirectToRoute('notary_practice_sheet', ['id' => $id]);
    }

    /**
     * 12.3.2 Da "completata" in poi i documenti sono in sola lettura anche per il
     * notaio: qui si blocca la POST, nel template spariscono i pulsanti. Per
     * intervenire di nuovo bisogna riaprire la pratica.
     */
    private function documentsLocked(Practice $practice): bool
    {
        if ($practice->areDocumentsEditable()) {
            return false;
        }

        $this->addFlash('notice_danger', sprintf(
            'La pratica è %s: i documenti non sono più modificabili. Riaprila per intervenire.',
            mb_strtolower($practice->getStatusLabel())
        ));

        return true;
    }

    /**
     * Carica la pratica dell'agenzia corrente.
     *
     * @return array{0: Company|null, 1: Practice|null}
     */
    private function requirePractice(int $id): array
    {
        $company = $this->currentAllowedCompany();
        if ($company === null) {
            $this->addFlash('notice_danger', 'Seleziona prima un\'agenzia.');

            return [null, null];
        }

        $practice = $this->registry->getManager('slave')->getRepository(Practice::class)->find($id);

        return [$company, $practice];
    }

    /**
     * 17.1 Agenzie (attive) in cui il notaio ha almeno una pratica assegnata, per nome.
     *
     * @return Company[]
     */
    private function allowedCompanies(): array
    {
        return $this->agencyAccess->companiesFor($this->getUser());
    }

    /**
     * Company selezionata in sessione, ma solo se è ancora tra quelle autorizzate
     * (difesa in profondità: la selezione passa già da selectAgency()).
     */
    private function currentAllowedCompany(): ?Company
    {
        $company = $this->companyService->getCurrentCompany();
        if ($company === null || !$this->agencyAccess->hasAccess($this->getUser(), $company)) {
            return null;
        }

        return $company;
    }

    /**
     * Carica pratica + documento (verificando che il documento appartenga alla pratica).
     *
     * @return array{0: Company|null, 1: Practice|null, 2: Document|null}
     */
    private function requireDocument(int $id, int $did): array
    {
        [$company, $practice] = $this->requirePractice($id);
        if ($practice === null) {
            return [$company, null, null];
        }

        return [$company, $practice, $this->findDocument($practice, $did)];
    }

    /** Allegato della pratica, per id; null se non le appartiene. */
    private function findDocument(Practice $practice, int $did): ?Document
    {
        if ($did <= 0) {
            return null;
        }
        $doc = $this->registry->getManager('slave')->getRepository(Document::class)->find($did);

        return $doc !== null && $doc->getPractice()?->getId() === $practice->getId() ? $doc : null;
    }

    /**
     * Carica pratica + riga documentale (12.3.2.1), verificando l'appartenenza.
     *
     * @return array{0: Company|null, 1: Practice|null, 2: PracticeDocument|null}
     */
    private function requireRow(int $id, int $rid): array
    {
        [$company, $practice] = $this->requirePractice($id);
        if ($practice === null) {
            return [$company, null, null];
        }

        return [$company, $practice, $this->findRow($practice, $rid)];
    }

    private function findRow(Practice $practice, int $rid): ?PracticeDocument
    {
        foreach ($practice->getPracticeDocuments() as $row) {
            if ((int) $row->getId() === $rid) {
                return $row;
            }
        }

        return null;
    }
}
