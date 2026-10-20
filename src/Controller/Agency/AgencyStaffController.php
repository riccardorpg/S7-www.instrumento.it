<?php

namespace App\Controller\Agency;

use App\Controller\Agency\Trait\AgencyContextTrait;
use App\Entity\Permission;
use App\Entity\User as StaffUser;
use App\Entity\UserPermission;
use App\Form\AgencyStaffType;
use App\Repository\PermissionRepository;
use App\Repository\UserRepository;
use App\Service\FilterService;
use App\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * 10. Staff dell'agenzia: chi ha accesso alla piattaforma, con ruolo (10.2.4),
 * permessi per sezione (10.1.5), invio credenziali (10.1.7) e attiva/sospendi (10.1.8).
 *
 * I membri dello staff sono gli utenti del DB agenzia (slave), gli stessi che l'admin
 * vede nella scheda cliente. Nuovo e modifica passano dai modali dell'elenco (AgencyStaffType).
 */
#[Route('/agenzia/staff')]
#[IsGranted('ROLE_USER')]
#[IsGranted(new Expression("is_granted('view', 'staff')"), message: 'Non hai accesso alla gestione dello staff.')]
class AgencyStaffController extends AbstractController
{
    use AgencyContextTrait;

    private const PER_PAGE = 20;

    public function __construct(private readonly ManagerRegistry $registry)
    {
    }

    /**
     * 10.1 Elenco delle persone che hanno accesso alla piattaforma, con i form di nuovo (10.2)
     * e modifica (anagrafica e ruolo) nei modali (CONVENZIONI 6): si inviano a questa pagina,
     * che li salva e torna all'elenco; se non sono validi la pagina si ripresenta con il modale
     * aperto. La modifica porta l'id nella query (?edit=ID), messo nell'action dal JS della riga.
     */
    #[Route('/lista', name: 'agency_staff', methods: ['GET', 'POST'])]
    public function agencyStaff(Request $request, PaginatorInterface $paginator, FormFactoryInterface $formFactory): Response
    {
        $em = $this->em();
        /** @var UserRepository $users */
        $users = $em->getRepository(StaffUser::class);
        $canEdit = $this->isGranted('edit', 'staff');

        $formNew = $formFactory->createNamed('agency_staff_new', AgencyStaffType::class, $this->newMember());
        if ($canEdit) {
            $formNew->handleRequest($request);
            if ($formNew->isSubmitted() && $formNew->isValid()) {
                $member = $formNew->getData();
                $member->setEmail(mb_strtolower((string) $member->getEmail()));
                $users->add($member);
                $this->addFlash('notice_success', 'Membro dello staff "' . $member->getFullName() . '" creato. Ora puoi inviargli le credenziali.');

                return $this->back();
            }
        }

        $editId = $request->query->getInt('edit');
        $edited = $canEdit && $editId > 0 ? $this->findMember($em, $editId) : null;
        $formEdit = $formFactory->createNamed('agency_staff_edit', AgencyStaffType::class, $edited ?? $this->newMember());
        if ($edited !== null) {
            $formEdit->handleRequest($request);
            if ($formEdit->isSubmitted()) {
                if ($formEdit->isValid()) {
                    $edited->setEmail(mb_strtolower((string) $edited->getEmail()));
                    $users->add($edited);
                    $this->addFlash('notice_success', 'Membro dello staff aggiornato.');

                    return $this->back();
                }
                // L'elenco mostra i dati salvati, non quelli rifiutati (che restano nel modale).
                $em->refresh($edited);
            }
        }

        $filters = FilterService::staffFilter($request);
        $records = $paginator->paginate(
            $users->queryStaffFiltered($this->company(), $filters, array_keys(StaffUser::STAFF_ROLES)),
            FilterService::page($request, 'agency_staff'),
            self::PER_PAGE
        );

        /** @var PermissionRepository $permissions */
        $permissions = $em->getRepository(Permission::class);

        return $this->render('role/agency/staff/list.html.twig', [
            'records' => $records,
            'filters' => $filters,
            'permissions' => $permissions->findOrdered(),
            'roles' => StaffUser::STAFF_ROLES,
            'formNew' => $formNew->createView(),
            'formEdit' => $formEdit->createView(),
        ]);
    }

    /** 10.1.5 Gestione permessi piattaforma: un livello per sezione. */
    #[Route('/{id}/permessi', name: 'agency_staff_permissions', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'staff')"))]
    public function agencyStaffPermissions(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('staffPermissions', (string) $request->request->get('_csrf_token'))) {
            return $this->back();
        }

        $em = $this->em();
        $member = $this->findMember($em, $id);

        /** @var array<string, string> $submitted slug => ''|'r'|'rw' */
        $submitted = (array) $request->request->all('permissions');

        // Livelli già assegnati, per slug: così aggiorniamo invece di cancellare e ricreare.
        $current = [];
        foreach ($member->getUserPermissions() as $up) {
            $current[(string) $up->getPermission()?->getSlug()] = $up;
        }

        foreach ($em->getRepository(Permission::class)->findAll() as $permission) {
            $slug = $permission->getSlug();
            $value = (string) ($submitted[$slug] ?? '');
            if (!in_array($value, ['', UserPermission::READ, UserPermission::READ_WRITE], true)) {
                $value = '';
            }

            $existing = $current[$slug] ?? null;
            if ($value === '') {
                // 10.1.5.3 "Nessuno" = nessuna riga.
                if ($existing !== null) {
                    $member->getUserPermissions()->removeElement($existing);
                    $em->remove($existing);
                }
                continue;
            }

            if ($existing === null) {
                $existing = (new UserPermission())->setUser($member)->setPermission($permission);
                $member->getUserPermissions()->add($existing);
                $em->persist($existing);
            }
            $existing->setValue($value);
        }

        $em->flush();
        $this->addFlash('notice_success', 'Permessi di "' . $member->getFullName() . '" aggiornati.');

        return $this->back();
    }

    /** 10.1.7 Invia credenziali: genera il codice usa-e-getta per creare la password. */
    #[Route('/{id}/credenziali', name: 'agency_staff_credentials', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'staff')"))]
    public function agencyStaffCredentials(int $id, Request $request, MailerService $mailer): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('staffCredentials', (string) $request->request->get('_csrf_token'))) {
            return $this->back();
        }

        $em = $this->em();
        $member = $this->findMember($em, $id);

        $member->setOneTimeCode(bin2hex(random_bytes(16)));
        $member->setExpirationOneTimeCode(new \DateTimeImmutable('+72 hours'));
        $em->flush();

        // 14.3 Link monouso per creare la password.
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

    /** 10.1.8 Attiva/sospendi l'account. */
    #[Route('/{id}/stato', name: 'agency_staff_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(new Expression("is_granted('edit', 'staff')"))]
    public function agencyStaffToggle(int $id, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('staffToggle', (string) $request->request->get('_csrf_token'))) {
            return $this->back();
        }

        $em = $this->em();
        $member = $this->findMember($em, $id);

        if ($member->getId() === $this->getUser()?->getId()) {
            $this->addFlash('notice_danger', 'Non puoi sospendere il tuo stesso account.');

            return $this->back();
        }

        $member->setActive(!$member->isActive());
        $em->flush();
        $this->addFlash('notice_success', sprintf(
            'Account di "%s" %s.',
            $member->getFullName(),
            $member->isActive() ? 'attivato' : 'sospeso'
        ));

        return $this->back();
    }

    // ===================== SUPPORTO =====================

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = $this->registry->getManager();

        return $em;
    }

    private function findMember(EntityManagerInterface $em, int $id): StaffUser
    {
        $member = $em->getRepository(StaffUser::class)->find($id);
        // Solo lo staff della propria agenzia (non amministratori della piattaforma né notai).
        if ($member === null || !$member->isAgencyUser() || $member->getCompany() !== $this->company()) {
            throw $this->createNotFoundException('Membro dello staff non trovato.');
        }

        return $member;
    }

    /** Nuovo membro dello staff: utente ROLE_USER della propria agenzia. */
    private function newMember(): StaffUser
    {
        return (new StaffUser())->setRole(StaffUser::ROLE_USER)->setCompany($this->company());
    }

    private function back(): RedirectResponse
    {
        return $this->redirectToRoute('agency_staff', [], Response::HTTP_SEE_OTHER);
    }
}
