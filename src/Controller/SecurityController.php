<?php

namespace App\Controller;

use App\Entity\Master\User as MasterUser;
use App\Entity\Slave\User as SlaveUser;
use App\Form\Model\PasswordCreate;
use App\Form\Model\PasswordRecover;
use App\Form\PasswordCreateType;
use App\Form\PasswordRecoverType;
use App\Repository\Master\CompanyRepository;
use App\Service\MailerService;
use App\Service\CompanyService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly CompanyService $companyService,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    // ================= 2. ACCEDI — step 1: codice personale (agenzia) =================

    #[Route('/accedi', name: 'login', methods: ['GET'])]
    public function login(AuthenticationUtils $utils): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectByRole();
        }

        // La pagina ospita anche il form credenziali (staff): serve l'esito dell'ultimo
        // tentativo, perché getLoginUrl() rimanda qui i POST senza "codice" né "staff".
        return $this->render('security/login.html.twig', [
            'last_username' => $utils->getLastUsername(),
            'error' => $utils->getLastAuthenticationError(),
            'recoverForm' => $this->recoverForm()->createView(),
        ]);
    }

    #[Route('/accedi', name: 'login_code_submit', methods: ['POST'])]
    public function loginCodeSubmit(Request $request, CompanyRepository $companies): Response
    {
        $code = trim((string) $request->request->get('code'));
        $company = $code !== '' ? $companies->findOneByCode($code) : null;

        if ($company === null || !$company->isActive()) {
            $this->addFlash('notice_danger', 'Codice non valido. Verifica il codice personale della tua agenzia.');

            return $this->redirectToRoute('login');
        }

        return $this->redirectToRoute('login_dedicated', ['code' => $company->getCode()]);
    }

    // ================= 3. PAGINA DI ACCESSO DEDICATA (brandizzata agenzia) =================

    #[Route('/accedi/{code}', name: 'login_dedicated', methods: ['GET'], requirements: ['code' => '[A-Za-z0-9._-]{1,64}'])]
    public function loginDedicated(string $code, CompanyRepository $companies, AuthenticationUtils $utils): Response
    {
        $company = $companies->findOneByCode($code);
        if ($company === null || !$company->isActive()) {
            $this->addFlash('notice_danger', 'Codice non valido.');

            return $this->redirectToRoute('login');
        }

        return $this->render('security/login_dedicated.html.twig', [
            'company' => $company,
            'last_username' => $utils->getLastUsername(),
            'error' => $utils->getLastAuthenticationError(),
            'recoverForm' => $this->recoverForm((string) $company->getCode())->createView(),
        ]);
    }

    // Accesso staff (ROLE_ADMIN / ROLE_NOTARY) senza codice.
    #[Route('/accesso-staff', name: 'login_staff', methods: ['GET'])]
    public function loginStaff(AuthenticationUtils $utils): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectByRole();
        }

        return $this->render('security/login_staff.html.twig', [
            'last_username' => $utils->getLastUsername(),
            'error' => $utils->getLastAuthenticationError(),
            'recoverForm' => $this->recoverForm()->createView(),
        ]);
    }

    #[Route('/accesso-verifica', name: 'login_check', methods: ['POST'])]
    public function loginCheck(): void
    {
        throw new \LogicException('Questa richiesta è intercettata dal firewall (custom_authenticator).');
    }

    #[Route('/disconnetti', name: 'logout')]
    public function logout(): void
    {
        throw new \LogicException('Questa richiesta è intercettata dalla chiave logout del firewall.');
    }

    // ================= 3.3 RECUPERA PASSWORD =================

    /**
     * Pagina e destinazione del modale "Recupera password" delle pagine di accesso. Se l'e-mail
     * non è valida la pagina si ripresenta con il campo segnato; se è valida il messaggio è
     * sempre lo stesso, che l'utente esista o no (evita la user enumeration).
     */
    #[Route('/recupera-password', name: 'password_recover', methods: ['GET', 'POST'])]
    public function passwordRecover(Request $request, MailerService $mailer): Response
    {
        $form = $this->recoverForm(trim((string) $request->query->get('code', '')));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var PasswordRecover $recover */
            $recover = $form->getData();
            $email = mb_strtolower($recover->getEmail());
            [$user, $companyCode] = $this->resolveUserByEmail($email, trim((string) $recover->getCode()));

            if ($user !== null) {
                $user->setOneTimeCode(bin2hex(random_bytes(16)));
                $user->setExpirationOneTimeCode(new \DateTimeImmutable('+3 hours'));
                $em = $user instanceof SlaveUser ? $this->registry->getManager('slave') : $this->registry->getManager('master');
                $em->flush();

                // 14.4 Recupero password: link monouso all'indirizzo dell'utente.
                $mailer->passwordRecovery(
                    (string) $user->getEmail(),
                    $user->getFullName(),
                    (string) $user->getOneTimeCode(),
                    $companyCode,
                    $user->getExpirationOneTimeCode(),
                );
            }

            // Messaggio generico (evita user enumeration)
            $this->addFlash('notice_success', 'Se l\'indirizzo è registrato, riceverai un\'email con le istruzioni per reimpostare la password.');

            return $this->redirectToRoute('login');
        }

        return $this->render('security/password_recover.html.twig', [
            'form' => $form->createView(),
            'code' => (string) $form->getData()->getCode(),
        ]);
    }

    // ================= 4. PAGINA CREAZIONE PASSWORD =================

    #[Route('/crea-password/{code}', name: 'password_create', methods: ['GET', 'POST'], requirements: ['code' => '[a-f0-9]{32}'])]
    public function passwordCreate(string $code, Request $request): Response
    {
        // Il codice agenzia sta nella query (anche al POST: è nell'action del form).
        $companyCode = trim((string) $request->query->get('c', ''));

        // Se è un utente agenzia, punta lo slave al suo DB prima di cercarlo.
        if ($companyCode !== '') {
            $company = $this->registry->getManager('master')->getRepository(\App\Entity\Master\Company::class)->findOneBy(['code' => $companyCode]);
            if ($company !== null) {
                $this->companyService->switchToCompany($company);
            }
        }

        $user = $this->findUserByOneTimeCode($code, $companyCode);
        if ($user === null) {
            $this->addFlash('notice_danger', 'Il link non è valido o è scaduto. Richiedi un nuovo recupero password.');

            return $this->redirectToRoute('login');
        }

        // 4.1 Le regole di robustezza stanno nel validator (config/validator/Model/PasswordCreate.yml).
        $form = $this->formFactory->createNamed('password_create', PasswordCreateType::class, new PasswordCreate(), [
            'action' => $this->generateUrl('password_create', ['code' => $code] + ($companyCode !== '' ? ['c' => $companyCode] : [])),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($this->hasher->hashPassword($user, (string) $form->getData()->getPassword()));
            $user->setOneTimeCode(null);
            $user->setExpirationOneTimeCode(null);
            $em = $user instanceof SlaveUser ? $this->registry->getManager('slave') : $this->registry->getManager('master');
            $em->flush();

            $this->addFlash('notice_success', 'Password impostata con successo. Ora puoi accedere.');

            return $this->redirectToRoute('login');
        }

        return $this->render('security/password_create.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    // ================= helper =================

    /**
     * Form "Recupera password": la pagina dedicata e il modale delle pagine di accesso inviano
     * entrambi a password_recover. $code è il codice dell'agenzia (vuoto per admin e notai).
     */
    private function recoverForm(string $code = ''): FormInterface
    {
        return $this->formFactory->createNamed('password_recover', PasswordRecoverType::class, new PasswordRecover($code !== '' ? $code : null), [
            'action' => $this->generateUrl('password_recover'),
        ]);
    }

    /**
     * Risoluzione code-scoped (come il login): con codice agenzia si cerca nello slave di
     * quel DB, altrimenti (staff) sul master. La stessa email può esistere in più agenzie.
     *
     * @return array{0: (MasterUser|SlaveUser|null), 1: string|null} [user, companyCode]
     */
    private function resolveUserByEmail(string $email, string $code = ''): array
    {
        if ($code !== '') {
            $company = $this->companyService->getCompanyByCode($code);
            if ($company !== null && $company->isActive()) {
                $this->companyService->switchToCompany($company);
                $agency = $this->registry->getManager('slave')->getRepository(SlaveUser::class)->findOneBy(['email' => $email]);
                if ($agency instanceof SlaveUser) {
                    return [$agency, $company->getCode()];
                }
            }

            return [null, null];
        }

        $master = $this->registry->getManager('master')->getRepository(MasterUser::class)->findOneBy(['email' => $email]);

        return [$master instanceof MasterUser ? $master : null, null];
    }

    private function findUserByOneTimeCode(string $code, string $companyCode): MasterUser|SlaveUser|null
    {
        if ($companyCode !== '') {
            $agency = $this->registry->getManager('slave')->getRepository(SlaveUser::class)->findOneBy(['oneTimeCode' => $code]);

            return ($agency instanceof SlaveUser && $agency->isOneTimeCodeValid($code)) ? $agency : null;
        }

        $master = $this->registry->getManager('master')->getRepository(MasterUser::class)->findOneBy(['oneTimeCode' => $code]);

        return ($master instanceof MasterUser && $master->isOneTimeCodeValid($code)) ? $master : null;
    }

    private function redirectByRole(): Response
    {
        return match (true) {
            $this->isGranted('ROLE_ADMIN')  => $this->redirectToRoute('admin_index'),
            $this->isGranted('ROLE_NOTARY') => $this->redirectToRoute('notary_index'),
            $this->isGranted('ROLE_AGENCY') => $this->redirectToRoute('agency_index'),
            default                         => $this->redirectToRoute('homepage'),
        };
    }
}
