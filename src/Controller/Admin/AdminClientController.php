<?php

namespace App\Controller\Admin;

use App\Entity\City;
use App\Entity\Company;
use App\Entity\DemoRequest;
use App\Entity\License;
use App\Entity\LicenseType;
use App\Entity\Practice;
use App\Entity\User as AgencyUser;
use App\Entity\Zip;
use App\Form\ClientFirstUserType;
use App\Form\ClientStaffType;
use App\Form\CompanyEditType;
use App\Form\CompanyNewType;
use App\Form\Model\ClientFirstUser;
use App\Repository\CompanyRepository;
use App\Repository\LicenseRepository;
use App\Repository\LicenseTypeRepository;
use App\Repository\SpaceExtraRepository;
use App\Repository\UserRepository;
use App\Service\CompanyLogoService;
use App\Service\CompanyService;
use App\Service\DocumentStorageService;
use App\Service\FilterService;
use App\Service\MailerService;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * 7. Gestione clienti (= agenzie/studi): una Company, con il suo staff e i suoi dati (company_id).
 */
#[Route('/amministratore/clienti')]
#[IsGranted('ROLE_ADMIN')]
class AdminClientController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly CompanyService $companyService,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly UserRepository $users,
    ) {
    }

    // ================= 7.1 ELENCO =================

    #[Route('/lista', name: 'admin_clients', methods: ['GET'])]
    public function adminClients(CompanyRepository $companies, LicenseTypeRepository $licenseTypes, PaginatorInterface $paginator, Request $request): Response
    {
        $filters = FilterService::clientFilter($request);
        $records = $paginator->paginate($companies->queryAllFiltered($filters), FilterService::page($request, 'admin_clients'), 20);

        return $this->render('role/admin/client/list.html.twig', [
            'records' => $records,
            'filters' => $filters,
            'licenseTypes' => $licenseTypes->findAllOrdered(),
        ]);
    }

    // ================= 7.1.10 NUOVO =================

    /**
     * Nuovo cliente. Con ?from=<id> il form nasce precompilato da una richiesta demo
     * (6.1.1): al salvataggio la richiesta viene marcata evasa e collegata al cliente,
     * la licenza parte come demo 30 giorni e nasce il primo utente dell'agenzia.
     * La stessa rotta mostra e gestisce il form (CONVENZIONI 6): se non è valido la pagina
     * si ripresenta con i campi segnati.
     */
    #[Route('/nuovo', name: 'admin_client_new', methods: ['GET', 'POST'])]
    public function adminClientNew(Request $request, FormFactoryInterface $formFactory, CompanyRepository $companies, MailerService $mailer): Response
    {
        $master = $this->registry->getManager();

        // La richiesta demo viaggia in query anche al POST (sta nell'action del form).
        $fromId = $request->query->getInt('from');
        $demoRequest = $fromId > 0 ? $master->getRepository(DemoRequest::class)->find($fromId) : null;
        if ($demoRequest !== null && !$demoRequest->isNew()) {
            $this->addFlash('notice_danger', 'La richiesta è già stata evasa o scartata.');

            return $this->redirectToRoute('admin_index');
        }

        $company = $demoRequest !== null ? $this->prefillFromRequest($demoRequest) : new Company();
        $form = $formFactory->createNamed('company_new', CompanyNewType::class, $company);
        $form->handleRequest($request);

        // Primo utente dell'agenzia (solo da richiesta demo): form a sé, nello stesso <form> HTML.
        $firstUserForm = null;
        if ($demoRequest !== null) {
            $firstUserForm = $formFactory->createNamed('client_first_user', ClientFirstUserType::class, new ClientFirstUser());
            $firstUserForm->handleRequest($request);
        }

        if ($form->isSubmitted()) {
            // Città e CAP dal picker (fuori dal form): servono anche a ripresentarli se il form torna indietro.
            [$city, $zip] = $this->resolveGeo($request);
            $company->setCity($city)->setZip($zip);

            // Logica esterna al validator: e-mail della richiesta valida e non già usata (un account = un'e-mail).
            if ($demoRequest !== null && !filter_var((string) $demoRequest->getEmail(), FILTER_VALIDATE_EMAIL)) {
                $form->addError(new FormError('La richiesta non ha un\'e-mail valida: crea il cliente senza conversione e aggiungi lo staff a mano.'));
            } elseif ($demoRequest !== null && $this->users->findOneByEmail((string) $demoRequest->getEmail()) !== null) {
                $form->addError(new FormError('L\'e-mail della richiesta è già usata da un altro utente: crea il cliente senza conversione e aggiungi lo staff a mano.'));
            }
        }

        $firstUserValid = $firstUserForm === null || ($firstUserForm->isSubmitted() && $firstUserForm->isValid());
        if ($form->isSubmitted() && $form->isValid() && $firstUserValid) {
            $company->setCode(mb_strtoupper((string) $company->getCode()))->setActive(true);
            $this->normalizeTaxData($company);

            // Conversione da richiesta demo: nasce e si assegna una licenza demo di 30 giorni (7.1.7).
            if ($demoRequest !== null) {
                $demoType = $master->getRepository(LicenseType::class)->findOneBy(['type' => LicenseType::DEMO]);
                if ($demoType !== null) {
                    $license = (new License())->setType($demoType);
                    $master->persist($license);
                    // L'assegnazione fa partire la licenza oggi (un anno): la demo dura 30 giorni.
                    $company->setLicense($license);
                    $license->setExpirationDate(new \DateTimeImmutable('today +30 days'));
                }
            }

            $companies->add($company);

            $message = 'Cliente "' . $company->getName() . '" creato.';

            if ($demoRequest !== null && $firstUserForm !== null) {
                /** @var ClientFirstUser $firstUser */
                $firstUser = $firstUserForm->getData();
                // Primo utente dell'agenzia: e-mail presa dalla richiesta, ruolo amministratore.
                // Password provvisoria casuale, come per lo staff: si attiva con "Invia credenziali".
                $user = (new AgencyUser())->setRole(AgencyUser::ROLE_USER)->setCompany($company);
                $user->setName($firstUser->getName())->setSurname($firstUser->getSurname())
                    ->setEmail(mb_strtolower((string) $demoRequest->getEmail()))
                    ->setActive(true);
                $user->setAdmin(true)->setStaffRole(AgencyUser::STAFF_ADMIN);
                $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(8))));
                // Codice monouso per l'invito (14.8): l'utente si crea la password dal link.
                $user->setOneTimeCode(bin2hex(random_bytes(16)));
                $user->setExpirationOneTimeCode(new \DateTimeImmutable('+72 hours'));
                $master->persist($user);
                $demoRequest->markConverted($company, $this->getUser()?->getUserIdentifier());
                $master->flush();

                // 14.8 Invito ad utilizzare la piattaforma, col link di creazione password.
                $invited = $mailer->invite(
                    (string) $user->getEmail(),
                    $user->getFullName(),
                    $company,
                    (string) $user->getOneTimeCode(),
                );

                $message .= ' Richiesta demo evasa, licenza demo di 30 giorni e utente '
                    . $user->getEmail() . ($invited
                        ? ' invitato via e-mail.'
                        : ' creato, ma l\'invio dell\'invito non è riuscito: usa "Invia credenziali".');
            }

            $this->addFlash('notice_success', $message);

            return $this->redirectToRoute('admin_client_sheet', ['id' => $company->getId()]);
        }

        return $this->render('role/admin/client/new.html.twig', [
            'demoRequest' => $demoRequest,
            'form' => $form->createView(),
            'firstUserForm' => $firstUserForm?->createView(),
        ]);
    }

    /**
     * Company transiente con i dati della richiesta demo: è il punto di partenza del form,
     * che al salvataggio la completa e la rende il nuovo cliente.
     */
    private function prefillFromRequest(DemoRequest $demoRequest): Company
    {
        $master = $this->registry->getManager();

        $company = new Company();
        $company->setName($demoRequest->getBusinessName())
            ->setClientType($demoRequest->getAccountType() === Company::TYPE_PROFESSIONAL ? Company::TYPE_PROFESSIONAL : Company::TYPE_COMPANY)
            ->setAddress($demoRequest->getAddress())
            ->setCivic($demoRequest->getCivic())
            ->setSdi($demoRequest->getSdi())
            ->setPec($demoRequest->getPec());

        // Nella richiesta città e CAP sono testo libero: li ricolleghiamo al catalogo geo
        // del master, così il picker parte già valorizzato (se il comune viene riconosciuto).
        $city = $demoRequest->getCity() !== null
            ? $master->getRepository(City::class)->findOneBy(['name' => $demoRequest->getCity()])
            : null;
        $company->setCity($city);
        if ($city !== null && $demoRequest->getZip() !== null) {
            foreach ($city->getZips() as $zip) {
                if ($zip->getCode() === $demoRequest->getZip()) {
                    $company->setZip($zip);
                    break;
                }
            }
        }

        return $company;
    }

    // ================= SOSPENDI / RIATTIVA =================

    #[Route('/{id}/stato', name: 'admin_client_toggle_active', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adminClientToggleActive(Company $company, Request $request): Response
    {
        if ($this->isCsrfTokenValid('client_active_' . $company->getId(), (string) $request->request->get('_csrf_token'))) {
            $company->setActive(!$company->isActive());
            $this->registry->getManager()->flush();
            $this->addFlash('notice_success', $company->isActive() ? 'Cliente riattivato.' : 'Cliente sospeso.');
        }

        return $this->redirectToRoute('admin_client_sheet', ['id' => $company->getId()]);
    }

    // ================= ELIMINA =================

    #[Route('/{id}/elimina', name: 'admin_client_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adminClientDelete(Company $company, Request $request, DocumentStorageService $storage, CompanyLogoService $logos): Response
    {
        if (!$this->isCsrfTokenValid('delete', (string) $request->request->get('_csrf_token'))) {
            return $this->redirectToRoute('admin_clients');
        }

        if (!$company->getCanDelete()) {
            $this->addFlash('notice_danger', 'Cliente non eliminabile: prima sospendilo.');

            return $this->redirectToRoute('admin_client_sheet', ['id' => $company->getId()]);
        }

        $master = $this->registry->getManager();
        $practicesDir = $storage->companyDir($company);
        $logos->delete($company);

        // Prima le pratiche (con righe documentali, allegati e avvisi a cascata): i clienti
        // che vi compaiono come parti non si possono cancellare finché c'è una pratica.
        // Poi il cliente: staff, clienti, configurazioni se ne vanno con lui (FK in cascata).
        $master->getConnection()->executeStatement('DELETE FROM eb_practice WHERE company_id = ?', [$company->getId()]);
        $master->remove($company);
        $master->flush();

        // File delle pratiche (best effort: il record è già rimosso).
        (new Filesystem())->remove($practicesDir);

        $this->addFlash('notice_success', 'Cliente eliminato.');

        return $this->redirectToRoute('admin_clients');
    }

    // ================= 7.1.9 SCHEDA =================

    /**
     * Scheda del cliente, con i form dei modali "Modifica dati fiscali" e "Nuovo utente staff"
     * (CONVENZIONI 6): si inviano a questa stessa pagina, che salva e torna alla scheda; se non
     * sono validi la scheda si ripresenta con il modale aperto e i campi segnati.
     * Le altre azioni della scheda (licenza, spazio, termini, stati) restano rotte POST a sé.
     */
    #[Route('/scheda/{id}', name: 'admin_client_sheet', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function adminClientSheet(Company $company, Request $request, FormFactoryInterface $formFactory, CompanyRepository $companies, LicenseRepository $licenses, LicenseTypeRepository $licenseTypes, SpaceExtraRepository $spaceExtras): Response
    {
        // 7.1.9.1 Modifica dati fiscali.
        $formEdit = $formFactory->createNamed('company_edit', CompanyEditType::class, $company);
        $formEdit->handleRequest($request);
        [$editCity, $editZip] = [$company->getCity(), $company->getZip()];
        if ($formEdit->isSubmitted()) {
            // Città e CAP dal picker (fuori dal form).
            [$editCity, $editZip] = $this->resolveGeo($request);
            if ($formEdit->isValid()) {
                $company->setCity($editCity)->setZip($editZip);
                $this->normalizeTaxData($company);
                $companies->add($company);
                $this->addFlash('notice_success', 'Cliente aggiornato.');

                return $this->redirectToRoute('admin_client_sheet', ['id' => $company->getId()]);
            }
            // Non valido: la scheda torna ai dati salvati, il modale tiene quelli inviati.
            $this->registry->getManager()->refresh($company);
        }

        // 7.1.9.6 Nuovo utente staff dell'agenzia (l'e-mail è unica su tutti gli utenti).
        $formStaff = $formFactory->createNamed('client_staff_new', ClientStaffType::class, (new AgencyUser())->setRole(AgencyUser::ROLE_USER)->setCompany($company));
        $formStaff->handleRequest($request);
        if ($formStaff->isSubmitted() && $formStaff->isValid()) {
            /** @var AgencyUser $user */
            $user = $formStaff->getData();
            $user->setEmail(mb_strtolower((string) $user->getEmail()))->setActive(true);
            // Password provvisoria casuale: verrà impostata dall'utente via "invia credenziali".
            $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(8))));
            $this->users->add($user);
            $this->addFlash('notice_success', 'Utente ' . $user->getEmail() . ' aggiunto. Usa "Invia credenziali" per l\'attivazione.');

            return $this->redirectToRoute('admin_client_sheet', ['id' => $company->getId()]);
        }

        // Staff caricato tutto (filtro lato JS nel tab), per e-mail.
        $staff = $this->users->findBy(['company' => $company, 'role' => AgencyUser::ROLE_USER], ['email' => 'ASC']);
        // 7.1.5 / 7.1.6 Pratiche del cliente, contate come fa l'area agenzia.
        $practiceCounts = $this->practiceCounts($company);

        return $this->render('role/admin/client/sheet.html.twig', [
            'company' => $company,
            'staff' => $staff,
            'practiceCounts' => $practiceCounts,
            'storageUsedMb' => $this->safeStorage($company),
            // Per i modali "Assegna licenza" e "Aggiungi pacchetto spazio".
            'freeCounts' => $licenses->countFreeByType(),
            'licenseTypes' => $licenseTypes->findAllOrdered(),
            'spaceExtras' => $spaceExtras->findAllOrdered(),
            'formEdit' => $formEdit->createView(),
            'formStaff' => $formStaff->createView(),
            // Il picker città del modale riparte da ciò che era stato scelto se il form torna indietro.
            'editCity' => $editCity,
            'editZip' => $editZip,
        ]);
    }

    /**
     * 7.1.5 attive / 7.1.6 archiviate: stessa convenzione dell'area agenzia, dove
     * "archiviata" è lo stato finale e tutto il resto è ancora in lavorazione.
     *
     * @return array{active: int, archived: int}
     */
    private function practiceCounts(Company $company): array
    {
        $base = $this->registry->getManager()->getRepository(Practice::class)->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.company = :company')->setParameter('company', $company);

        return [
            'active' => (int) (clone $base)->andWhere('p.status <> :archived')
                ->setParameter('archived', Practice::STATUS_ARCHIVED)->getQuery()->getSingleScalarResult(),
            'archived' => (int) (clone $base)->andWhere('p.status = :archived')
                ->setParameter('archived', Practice::STATUS_ARCHIVED)->getQuery()->getSingleScalarResult(),
        ];
    }

    // ---- 7.1.9.4 / 7.1.9.5 Licenza: assegna o cambia, rinnova, revoca (come eaml.software) ----

    /**
     * Assegna o cambia licenza (modale "Assegna / Cambia licenza": tipo e data di attivazione).
     * Con lo stesso tipo della licenza attuale cambia solo la data di attivazione; con un altro tipo
     * il cliente riceve la prima licenza libera di quel tipo e la vecchia torna libera.
     */
    #[Route('/{id}/licenza/assegna', name: 'admin_client_license_assign', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adminClientLicenseAssign(Company $company, Request $request, LicenseRepository $licenses, LicenseTypeRepository $licenseTypes): Response
    {
        if (!$this->isCsrfTokenValid('clientLicenseAssign', (string) $request->request->get('_csrf_token'))) {
            return $this->redirectToRoute('admin_client_sheet', ['id' => $company->getId()]);
        }

        $activation = $this->activationDate($request);
        $type = $licenseTypes->find((int) $request->request->get('type_id'));
        if ($activation === null || $type === null) {
            $this->addFlash('notice_warning', 'Seleziona il tipo di licenza e la data di attivazione.');

            return $this->redirectToRoute('admin_client_sheet', ['id' => $company->getId()]);
        }

        $current = $company->getLicense();
        if ($current !== null && $current->getType() === $type) {
            $current->start($activation);
            $license = $current;
        } else {
            $license = $licenses->findFirstFreeByType($type);
            if ($license === null) {
                $this->addFlash('notice_warning', 'Non ci sono licenze ' . $type->getLabel() . ' libere: creane dall\'elenco licenze.');

                return $this->redirectToRoute('admin_client_sheet', ['id' => $company->getId()]);
            }
            $company->setLicense($license); // la vecchia, se c'era, torna libera
            $license->start($activation);
        }
        $this->registry->getManager()->flush();
        $this->addFlash('notice_success', ($current === null ? 'Licenza assegnata: ' : 'Licenza modificata: ') . $license->getInfo() . '.');

        return $this->redirectToRoute('admin_client_sheet', ['id' => $company->getId()]);
    }

    /** Rinnovo (modale "Rinnova"): nuova data di attivazione sulla stessa licenza, un anno da lì. */
    #[Route('/{id}/licenza/rinnova', name: 'admin_client_license_renew', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adminClientLicenseRenew(Company $company, Request $request): Response
    {
        $license = $company->getLicense();
        if ($license === null || !$this->isCsrfTokenValid('clientLicenseRenew', (string) $request->request->get('_csrf_token'))) {
            return $this->redirectToRoute('admin_client_sheet', ['id' => $company->getId()]);
        }

        $activation = $this->activationDate($request);
        if ($activation === null) {
            $this->addFlash('notice_warning', 'Seleziona la data di attivazione.');
        } else {
            $license->start($activation);
            $this->registry->getManager()->flush();
            $this->addFlash('notice_success', 'Licenza rinnovata: ' . $license->getInfo() . '.');
        }

        return $this->redirectToRoute('admin_client_sheet', ['id' => $company->getId()]);
    }

    /** Revoca: il cliente resta senza licenza, la licenza torna libera. */
    #[Route('/{id}/licenza/revoca', name: 'admin_client_license_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adminClientLicenseRevoke(Company $company, Request $request): Response
    {
        if ($company->getLicense() !== null && $this->isCsrfTokenValid('client_license_' . $company->getId(), (string) $request->request->get('_csrf_token'))) {
            $company->setLicense(null);
            $this->registry->getManager()->flush();
            $this->addFlash('notice_success', 'Licenza revocata: ora è di nuovo libera.');
        }

        return $this->redirectToRoute('admin_client_sheet', ['id' => $company->getId()]);
    }

    /** Data di attivazione dai modali licenza (datepicker, gg-mm-aaaa); null se manca o non è valida. */
    private function activationDate(Request $request): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!d-m-Y', trim((string) $request->request->get('activation_date')));

        return $date === false ? null : $date;
    }


    // ---- 7.1.8 Pacchetto spazio aggiuntivo (uno solo: si sostituisce) ----

    #[Route('/{id}/spazio/assegna', name: 'admin_client_space_set', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adminClientSpaceSet(Company $company, Request $request, SpaceExtraRepository $spaceExtras): Response
    {
        if ($this->isCsrfTokenValid('clientSpaceSet', (string) $request->request->get('_csrf_token'))) {
            $extra = $spaceExtras->find((int) $request->request->get('space_extra_id'));
            if ($extra === null) {
                $this->addFlash('notice_danger', 'Seleziona un pacchetto spazio.');
            } elseif ($company->getSpaceExtra() === $extra) {
                $this->addFlash('notice_danger', 'Il pacchetto ' . $extra->getLabel() . ' è già quello del cliente.');
            } else {
                $previous = $company->getSpaceExtra();
                $company->setSpaceExtra($extra);
                $this->registry->getManager()->flush();
                $this->addFlash('notice_success', $previous === null
                    ? 'Pacchetto spazio ' . $extra->getLabel() . ' assegnato.'
                    : 'Pacchetto spazio cambiato da ' . $previous->getLabel() . ' a ' . $extra->getLabel() . '.');
            }
        }

        return $this->redirectToRoute('admin_client_sheet', ['id' => $company->getId()]);
    }

    #[Route('/{id}/spazio/rimuovi', name: 'admin_client_space_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adminClientSpaceRemove(Company $company, Request $request): Response
    {
        $extra = $company->getSpaceExtra();
        if ($extra !== null && $this->isCsrfTokenValid('client_license_' . $company->getId(), (string) $request->request->get('_csrf_token'))) {
            $company->setSpaceExtra(null);
            $this->registry->getManager()->flush();
            $this->addFlash('notice_success', 'Pacchetto spazio ' . $extra->getLabel() . ' rimosso.');
        }

        return $this->redirectToRoute('admin_client_sheet', ['id' => $company->getId()]);
    }

    // ---- 7.1.9.7 Contratto termini e condizioni ----

    #[Route('/{id}/termini', name: 'admin_client_terms', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function adminClientTerms(Company $company, Request $request, MailerService $mailer): Response
    {
        if ($this->isCsrfTokenValid('client_terms_' . $company->getId(), (string) $request->request->get('_csrf_token'))) {
            $accepting = !$company->isTermsAccepted();
            $company->setTermsAcceptedAt($accepting ? new \DateTimeImmutable() : null);
            $this->registry->getManager()->flush();
            $this->addFlash('notice_success', 'Stato contratto aggiornato.');

            // 14.9 Solo all'accettazione: ricevuta al cliente, copia all'indirizzo dedicato.
            if ($accepting) {
                $to = $this->companyService->getPrimaryContactEmail($company);
                if ($to === null) {
                    $this->addFlash('notice_danger', 'Nessun indirizzo a cui inviare la ricevuta del contratto: il cliente non ha utenti attivi.');
                } elseif (!$mailer->termsAccepted($company, $to)) {
                    $this->addFlash('notice_danger', 'Invio della ricevuta del contratto a ' . $to . ' non riuscito.');
                }
            }
        }

        return $this->redirectToRoute('admin_client_sheet', ['id' => $company->getId()]);
    }

    // ================= 7.1.9.6 GESTIONE STAFF DELL'AGENZIA =================

    #[Route('/{id}/staff/{sid}/stato', name: 'admin_client_staff_toggle', methods: ['POST'], requirements: ['id' => '\d+', 'sid' => '\d+'])]
    public function adminClientStaffToggle(Company $company, int $sid, Request $request): Response
    {
        if ($this->isCsrfTokenValid('client_staff_' . $company->getId(), (string) $request->request->get('_csrf_token'))) {
            $user = $this->staffMember($company, $sid);
            if ($user !== null) {
                $user->setActive(!$user->isActive());
                $this->registry->getManager()->flush();
            }
        }

        return $this->redirectToRoute('admin_client_sheet', ['id' => $company->getId()]);
    }

    #[Route('/{id}/staff/{sid}/admin', name: 'admin_client_staff_admin', methods: ['POST'], requirements: ['id' => '\d+', 'sid' => '\d+'])]
    public function adminClientStaffAdmin(Company $company, int $sid, Request $request): Response
    {
        if ($this->isCsrfTokenValid('client_staff_' . $company->getId(), (string) $request->request->get('_csrf_token'))) {
            $user = $this->staffMember($company, $sid);
            if ($user !== null) {
                $user->setAdmin(!$user->isAdmin());
                $this->registry->getManager()->flush();
            }
        }

        return $this->redirectToRoute('admin_client_sheet', ['id' => $company->getId()]);
    }

    #[Route('/{id}/staff/{sid}/credenziali', name: 'admin_client_staff_credentials', methods: ['POST'], requirements: ['id' => '\d+', 'sid' => '\d+'])]
    public function adminClientStaffCredentials(Company $company, int $sid, Request $request, MailerService $mailer): Response
    {
        if ($this->isCsrfTokenValid('client_staff_' . $company->getId(), (string) $request->request->get('_csrf_token'))) {
            $user = $this->staffMember($company, $sid);
            if ($user !== null) {
                $user->setOneTimeCode(bin2hex(random_bytes(16)));
                $user->setExpirationOneTimeCode(new \DateTimeImmutable('+72 hours'));
                $this->registry->getManager()->flush();

                // 14.3 Link di creazione password.
                $sent = $mailer->passwordCreate(
                    (string) $user->getEmail(),
                    $user->getFullName(),
                    (string) $user->getOneTimeCode(),
                    $user->getExpirationOneTimeCode(),
                );
                $this->addFlash(
                    $sent ? 'notice_success' : 'notice_danger',
                    $sent
                        ? 'Credenziali inviate a ' . $user->getEmail() . '.'
                        : 'Credenziali generate, ma l\'invio dell\'e-mail a ' . $user->getEmail() . ' non è riuscito.'
                );
            }
        }

        return $this->redirectToRoute('admin_client_sheet', ['id' => $company->getId()]);
    }

    // ================= helper =================

    /** Membro dello staff di questa agenzia, null se l'id è di un altro utente. */
    private function staffMember(Company $company, int $sid): ?AgencyUser
    {
        $user = $this->users->find($sid);

        return $user !== null && $user->isAgencyUser() && $user->getCompany() === $company ? $user : null;
    }

    /**
     * Risolve city_id/zip_id (dal picker città/CAP) in entità City/Zip.
     *
     * @return array{0: ?City, 1: ?Zip}
     */
    private function resolveGeo(Request $request): array
    {
        $master = $this->registry->getManager();
        $cityId = (int) $request->request->get('city_id');
        $zipId = (int) $request->request->get('zip_id');

        return [
            $cityId ? $master->getRepository(City::class)->find($cityId) : null,
            $zipId ? $master->getRepository(Zip::class)->find($zipId) : null,
        ];
    }

    /** Codice fiscale e codice SDI si salvano in maiuscolo (dopo la validazione, come l'e-mail minuscola). */
    private function normalizeTaxData(Company $company): void
    {
        $company->setTaxCode($company->getTaxCode() !== null ? mb_strtoupper($company->getTaxCode()) : null)
            ->setSdi($company->getSdi() !== null ? mb_strtoupper($company->getSdi()) : null);
    }

    private function safeStorage(Company $company): float
    {
        return $this->companyService->getStorageUsedMb($company);
    }
}
