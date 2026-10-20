<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\NotaryType;
use App\Repository\CompanyRepository;
use App\Repository\UserRepository;
use App\Service\FilterService;
use App\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestione notai (ROLE_NOTARY): elenco, creazione, modifica dati e invio credenziali.
 *
 * 17.1 Le agenzie NON si abbinano da qui: l'accesso lo dà l'agenzia assegnando una
 * pratica al notaio, quindi l'elenco mostra solo ciò che ne risulta.
 */
#[Route('/amministratore/notai')]
#[IsGranted('ROLE_ADMIN')]
class AdminNotaryController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    /**
     * Elenco, con i form "Nuovo notaio" e "Modifica dati" nei modali (CONVENZIONI 6): i form si
     * inviano a questa stessa pagina, che li salva e torna all'elenco; se non sono validi la pagina
     * si ripresenta con il modale aperto e i campi segnati.
     * La modifica porta l'id nella query (?edit=ID), messo nell'action del form dal JS della riga.
     */
    #[Route('/lista', name: 'admin_notaries', methods: ['GET', 'POST'])]
    public function adminNotaries(Request $request, UserRepository $users, CompanyRepository $companies, PaginatorInterface $paginator, FormFactoryInterface $formFactory): Response
    {
        // Nuovo notaio (utente master ROLE_NOTARY).
        $formNew = $formFactory->createNamed('notary_new', NotaryType::class, new User());
        $formNew->handleRequest($request);
        if ($formNew->isSubmitted() && $formNew->isValid()) {
            /** @var User $user */
            $user = $formNew->getData();
            $user->setEmail(mb_strtolower((string) $user->getEmail()))->setRole('ROLE_NOTARY')->setActive(true);
            // Password provvisoria casuale: verrà impostata dall'utente via "invia credenziali".
            $user->setPassword($this->hasher->hashPassword($user, bin2hex(random_bytes(8))));
            $users->add($user);
            $this->addFlash('notice_success', 'Notaio ' . $user->getEmail() . ' creato. Usa "Invia credenziali" per l\'attivazione.');

            return $this->redirectToRoute('admin_notaries');
        }

        // Modifica dati del notaio: solo utenti ROLE_NOTARY.
        $editId = $request->query->getInt('edit');
        $edited = $editId > 0 ? $users->find($editId) : null;
        if ($edited !== null && !$edited->isNotary()) {
            $edited = null;
        }
        $formEdit = $formFactory->createNamed('notary_edit', NotaryType::class, $edited ?? new User());
        if ($edited !== null) {
            $formEdit->handleRequest($request);
            if ($formEdit->isSubmitted() && $formEdit->isValid()) {
                $edited->setEmail(mb_strtolower((string) $edited->getEmail()));
                $users->add($edited);
                $this->addFlash('notice_success', 'Dati del notaio aggiornati.');

                return $this->redirectToRoute('admin_notaries');
            }
            if ($formEdit->isSubmitted()) {
                // Non valido: la riga dell'elenco torna ai dati salvati, il modale tiene quelli inviati.
                $this->em->refresh($edited);
            }
        }

        $filters = FilterService::notaryFilter($request);

        // 17.1 "Agenzia" filtra su chi ha una pratica assegnata in quell'agenzia.
        $records = $paginator->paginate($users->queryNotariesFiltered($filters), FilterService::page($request, 'admin_notaries'), 20);

        return $this->render('role/admin/notary/list.html.twig', [
            'records' => $records,
            'filters' => $filters,
            'companies' => $companies->findBy(['active' => true], ['name' => 'ASC']),
            // 17.1 Agenzie di ciascun notaio, per id.
            'agenciesByNotary' => $companies->findByNotary(),
            'formNew' => $formNew->createView(),
            'formEdit' => $formEdit->createView(),
        ]);
    }

    /** Invia (genera) le credenziali di attivazione. */
    #[Route('/{id}/credenziali', name: 'admin_notary_credentials', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function adminNotaryCredentials(int $id, Request $request, UserRepository $users, MailerService $mailer): RedirectResponse
    {
        $notary = $this->findNotary($id, $users);
        if ($notary === null) {
            return $this->redirectToRoute('admin_notaries');
        }
        if (!$this->isCsrfTokenValid('notaryCredentials', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('notice_danger', 'Token non valido, riprova.');

            return $this->redirectToRoute('admin_notaries');
        }

        $notary->setOneTimeCode(bin2hex(random_bytes(16)));
        $notary->setExpirationOneTimeCode(new \DateTimeImmutable('+72 hours'));
        $this->em->flush();
        // 14.3 Il notaio è un utente master: nessun codice agenzia nel link.
        $sent = $mailer->passwordCreate(
            (string) $notary->getEmail(),
            $notary->getFullName(),
            (string) $notary->getOneTimeCode(),
            null,
            $notary->getExpirationOneTimeCode(),
        );
        $this->addFlash(
            $sent ? 'notice_success' : 'notice_danger',
            $sent
                ? 'Credenziali inviate a ' . $notary->getEmail() . '.'
                : 'Credenziali generate, ma l\'invio dell\'e-mail a ' . $notary->getEmail() . ' non è riuscito.'
        );

        return $this->redirectToRoute('admin_notaries');
    }

    private function findNotary(int $id, UserRepository $users): ?User
    {
        $notary = $users->find($id);
        if ($notary === null || !$notary->isNotary()) {
            $this->addFlash('notice_danger', 'Notaio non trovato.');

            return null;
        }

        return $notary;
    }
}
