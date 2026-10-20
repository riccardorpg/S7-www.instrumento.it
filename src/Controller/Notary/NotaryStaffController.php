<?php

namespace App\Controller\Notary;

use App\Entity\Practice;
use App\Entity\User;
use App\Form\NotaryStaffEditType;
use App\Form\NotaryStaffType;
use App\Repository\UserRepository;
use App\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Staff del notaio: i collaboratori (ROLE_NOTARY_STAFF) a cui assegna le sue pratiche. Il notaio
 * ne crea di nuovi (e invia loro le credenziali) o aggiunge, cercandolo per e-mail, un collaboratore
 * già registrato (anche nello staff di un altro notaio). Dalla scheda del collaboratore sceglie le
 * pratiche che gli assegna. Solo per il notaio: i collaboratori non gestiscono lo staff.
 */
#[Route('/notaio/staff')]
#[IsGranted('ROLE_NOTARY')]
class NotaryStaffController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
    ) {
    }

    /** Elenco dello staff, con il form "Nuovo collaboratore" nel modale (CONVENZIONI 6). */
    #[Route('/lista', name: 'notary_staff', methods: ['GET', 'POST'])]
    public function notaryStaff(Request $request, FormFactoryInterface $formFactory, UserPasswordHasherInterface $hasher): Response
    {
        $notary = $this->notary();

        $member = (new User())->setRole(User::ROLE_NOTARY_STAFF)->setActive(true);
        $formNew = $formFactory->createNamed('notary_staff_new', NotaryStaffType::class, $member);
        $formNew->handleRequest($request);
        if ($formNew->isSubmitted() && $formNew->isValid()) {
            $member->setEmail(mb_strtolower((string) $member->getEmail()));
            // Password provvisoria casuale: la imposta il collaboratore dal link di "Invia credenziali".
            $member->setPassword($hasher->hashPassword($member, bin2hex(random_bytes(8))));
            $notary->addCollaborator($member);
            $this->em->persist($member);
            $this->em->flush();
            $this->addFlash('notice_success', 'Collaboratore "' . $member->getFullName() . '" creato. Ora puoi inviargli le credenziali.');

            return $this->back();
        }

        return $this->render('role/notary/staff/list.html.twig', [
            'records' => $notary->getCollaborators(),
            'assignedCounts' => $this->assignedCounts($notary),
            'formNew' => $formNew->createView(),
        ]);
    }

    /** Ricerca per e-mail dei collaboratori già registrati, per l'autocomplete "Aggiungi esistente". */
    #[Route('/api/cerca', name: 'notary_staff_search', methods: ['GET'])]
    public function notaryStaffSearch(Request $request): JsonResponse
    {
        $items = array_map(static fn (User $u) => [
            'id' => $u->getId(),
            'label' => $u->getFullName() . ' (' . $u->getEmail() . ')',
        ], $this->users->searchNotaryStaff($this->notary(), (string) $request->query->get('q', '')));

        return $this->json($items);
    }

    /** Aggiunge allo staff un collaboratore già registrato, scelto dall'autocomplete. */
    #[Route('/aggiungi', name: 'notary_staff_add', methods: ['POST'])]
    public function notaryStaffAdd(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('notaryStaffAdd', (string) $request->request->get('_csrf_token'))) {
            return $this->back();
        }

        $member = $this->users->find($request->request->getInt('user_id'));
        if ($member === null || !$member->isNotaryStaff() || !$member->isActive()) {
            $this->addFlash('notice_danger', 'Scegli un collaboratore dall\'elenco dei risultati.');

            return $this->back();
        }

        $notary = $this->notary();
        if ($notary->hasCollaborator($member)) {
            $this->addFlash('notice_warning', $member->getFullName() . ' fa già parte del tuo staff.');

            return $this->back();
        }

        $notary->addCollaborator($member);
        $this->em->flush();
        $this->addFlash('notice_success', $member->getFullName() . ' aggiunto al tuo staff.');

        return $this->back();
    }

    /**
     * Scheda del collaboratore, a due schede: i dati, con il form "Modifica" (nome e cognome) nel
     * modale (CONVENZIONI 6), e le pratiche del notaio che gli sono assegnate. Si assegnano una alla
     * volta dal modale "Assegna pratica" (ricerca in notary_staff_practice_search).
     */
    #[Route('/scheda/{id}', name: 'notary_staff_sheet', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function notaryStaffSheet(int $id, Request $request, FormFactoryInterface $formFactory): Response
    {
        $member = $this->findMember($id);

        $formEdit = $formFactory->createNamed('notary_staff_edit', NotaryStaffEditType::class, $member);
        $formEdit->handleRequest($request);
        if ($formEdit->isSubmitted()) {
            if ($formEdit->isValid()) {
                $this->em->flush();
                $this->addFlash('notice_success', 'Collaboratore aggiornato.');

                return $this->redirectToRoute('notary_staff_sheet', ['id' => $id], Response::HTTP_SEE_OTHER);
            }
            // La scheda mostra i dati salvati, il modale tiene quelli rifiutati.
            $this->em->refresh($member);
        }

        return $this->render('role/notary/staff/sheet.html.twig', [
            'member' => $member,
            // Solo le pratiche del notaio già assegnate al collaboratore.
            'practices' => $this->notaryPracticesQb()
                ->andWhere(':member MEMBER OF p.notaryStaff')->setParameter('member', $member)
                ->getQuery()->getResult(),
            'formEdit' => $formEdit->createView(),
        ]);
    }

    /**
     * Ricerca per il modale "Assegna pratica": pratiche del notaio non ancora assegnate al
     * collaboratore, per codice, agenzia, acquirente o venditore. Senza testo, le più recenti.
     */
    #[Route('/{id}/pratiche/cerca', name: 'notary_staff_practice_search', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function notaryStaffPracticeSearch(int $id, Request $request): JsonResponse
    {
        $member = $this->findMember($id);
        $q = trim((string) $request->query->get('q', ''));

        $qb = $this->notaryPracticesQb()
            ->leftJoin('p.buyer', 'b')->addSelect('b')
            ->leftJoin('p.seller', 's')->addSelect('s')
            ->andWhere(':member NOT MEMBER OF p.notaryStaff')->setParameter('member', $member)
            ->setMaxResults(15);
        if ($q !== '') {
            $qb->andWhere("p.number LIKE :q OR c.name LIKE :q
                OR CONCAT(COALESCE(b.name, ''), ' ', COALESCE(b.surname, '')) LIKE :q
                OR CONCAT(COALESCE(s.name, ''), ' ', COALESCE(s.surname, '')) LIKE :q")
                ->setParameter('q', '%' . addcslashes($q, '%_') . '%');
        }

        return $this->json(array_map(static fn (Practice $p) => [
            'id' => $p->getId(),
            'number' => $p->getNumber(),
            'company' => (string) $p->getCompany()?->getName(),
            'buyer' => $p->getBuyer()?->getFullName() ?? '-',
            'seller' => $p->getSeller()?->getFullName() ?? '-',
        ], $qb->getQuery()->getResult()));
    }

    /** Assegna al collaboratore una pratica del notaio (scelta nel modale "Assegna pratica"). */
    #[Route('/{id}/pratiche/assegna', name: 'notary_staff_practice_assign', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function notaryStaffPracticeAssign(int $id, Request $request): RedirectResponse
    {
        $member = $this->findMember($id);
        if ($this->isCsrfTokenValid('notaryStaffPracticeAssign', (string) $request->request->get('_csrf_token'))) {
            $practice = $this->findNotaryPractice($request->request->getInt('practice_id'));
            if ($practice === null) {
                $this->addFlash('notice_danger', 'Scegli una pratica dall\'elenco dei risultati.');
            } else {
                $practice->addNotaryStaff($member);
                $this->em->flush();
                $this->addFlash('notice_success', 'Pratica ' . $practice->getNumber() . ' assegnata a ' . $member->getFullName() . '.');
            }
        }

        return $this->redirectToRoute('notary_staff_sheet', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    /** Toglie al collaboratore una pratica assegnata. */
    #[Route('/{id}/pratiche/{pid}/togli', name: 'notary_staff_practice_unassign', methods: ['POST'], requirements: ['id' => '\d+', 'pid' => '\d+'])]
    public function notaryStaffPracticeUnassign(int $id, int $pid, Request $request): RedirectResponse
    {
        $member = $this->findMember($id);
        $practice = $this->findNotaryPractice($pid);
        if ($practice !== null && $this->isCsrfTokenValid('notaryStaffPracticeUnassign', (string) $request->request->get('_csrf_token'))) {
            $practice->removeNotaryStaff($member);
            $this->em->flush();
            $this->addFlash('notice_success', 'Pratica ' . $practice->getNumber() . ' tolta a ' . $member->getFullName() . '.');
        }

        return $this->redirectToRoute('notary_staff_sheet', ['id' => $id], Response::HTTP_SEE_OTHER);
    }

    /** Invia credenziali: codice monouso per creare la password (come per lo staff delle agenzie). */
    #[Route('/{id}/credenziali', name: 'notary_staff_credentials', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function notaryStaffCredentials(int $id, Request $request, MailerService $mailer): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('notaryStaffCredentials', (string) $request->request->get('_csrf_token'))) {
            return $this->back();
        }

        $member = $this->findMember($id);
        $member->setOneTimeCode(bin2hex(random_bytes(16)));
        $member->setExpirationOneTimeCode(new \DateTimeImmutable('+72 hours'));
        $this->em->flush();

        $sent = $mailer->passwordCreate(
            (string) $member->getEmail(),
            $member->getFullName(),
            (string) $member->getOneTimeCode(),
            $member->getExpirationOneTimeCode(),
        );
        $this->addFlash(
            $sent ? 'notice_success' : 'notice_danger',
            $sent
                ? 'Credenziali inviate a ' . $member->getEmail() . '.'
                : 'Credenziali generate, ma l\'invio dell\'e-mail a ' . $member->getEmail() . ' non è riuscito.'
        );

        return $this->back();
    }

    /**
     * Toglie il collaboratore dallo staff del notaio, con le pratiche del notaio che gli erano
     * assegnate. L'utente resta (può essere nello staff di altri notai).
     */
    #[Route('/{id}/rimuovi', name: 'notary_staff_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function notaryStaffRemove(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('notaryStaffRemove', (string) $request->request->get('_csrf_token'))) {
            return $this->back();
        }

        $member = $this->findMember($id);
        foreach ($this->notaryPractices() as $practice) {
            $practice->removeNotaryStaff($member);
        }
        $this->notary()->removeCollaborator($member);
        $this->em->flush();
        $this->addFlash('notice_success', $member->getFullName() . ' tolto dal tuo staff.');

        return $this->back();
    }

    // ===================== SUPPORTO =====================

    private function notary(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isNotary()) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /** Collaboratore dello staff del notaio collegato: gli altri utenti per lui non esistono. */
    private function findMember(int $id): User
    {
        $member = $this->users->find($id);
        if ($member === null || !$member->isNotaryStaff() || !$this->notary()->hasCollaborator($member)) {
            throw $this->createNotFoundException('Collaboratore non trovato nel tuo staff.');
        }

        return $member;
    }

    /**
     * Pratiche assegnate al notaio collegato, di agenzie attive: le più recenti per prime.
     *
     * @return Practice[]
     */
    private function notaryPractices(): array
    {
        return $this->notaryPracticesQb()->getQuery()->getResult();
    }

    /** Base delle query sulle pratiche del notaio collegato (alias p, agenzia c). */
    private function notaryPracticesQb(): QueryBuilder
    {
        return $this->em->getRepository(Practice::class)->createQueryBuilder('p')
            ->join('p.company', 'c')->addSelect('c')
            ->where('p.notary = :notary')->setParameter('notary', $this->notary())
            ->andWhere('c.active = true')
            ->orderBy('p.createdAt', 'DESC');
    }

    /** Pratica del notaio collegato, null se l'id è di un'altra. */
    private function findNotaryPractice(int $id): ?Practice
    {
        return $id > 0
            ? $this->notaryPracticesQb()->andWhere('p.id = :id')->setParameter('id', $id)->getQuery()->getOneOrNullResult()
            : null;
    }

    /**
     * Quante pratiche del notaio sono assegnate a ciascun collaboratore.
     *
     * @return array<int, int> id collaboratore => pratiche
     */
    private function assignedCounts(User $notary): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('ns.id AS id, COUNT(p.id) AS qty')
            ->from(Practice::class, 'p')
            ->join('p.notaryStaff', 'ns')
            ->where('p.notary = :notary')->setParameter('notary', $notary)
            ->groupBy('ns.id')
            ->getQuery()->getArrayResult();

        return array_column(array_map(static fn (array $r) => ['id' => (int) $r['id'], 'qty' => (int) $r['qty']], $rows), 'qty', 'id');
    }

    private function back(): RedirectResponse
    {
        return $this->redirectToRoute('notary_staff', [], Response::HTTP_SEE_OTHER);
    }
}
