<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\Model\PasswordCreate;
use App\Form\Model\PasswordRecover;
use App\Form\PasswordCreateType;
use App\Form\PasswordRecoverType;
use App\Repository\UserRepository;
use App\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    // ================= 2. ACCEDI: un solo accesso per amministratori, notai e agenzie =================

    #[Route('/accedi', name: 'login', methods: ['GET'])]
    public function login(AuthenticationUtils $utils): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectByRole();
        }

        return $this->render('security/login.html.twig', [
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
     * Pagina e destinazione del modale "Recupera password" della pagina di accesso. Se l'e-mail
     * non è valida la pagina si ripresenta con il campo segnato; se è valida il messaggio è
     * sempre lo stesso, che l'utente esista o no (evita la user enumeration).
     */
    #[Route('/recupera-password', name: 'password_recover', methods: ['GET', 'POST'])]
    public function passwordRecover(Request $request, MailerService $mailer): Response
    {
        $form = $this->recoverForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var PasswordRecover $recover */
            $recover = $form->getData();
            $user = $this->users->findOneByEmail($recover->getEmail());

            if ($user !== null) {
                $user->setOneTimeCode(bin2hex(random_bytes(16)));
                $user->setExpirationOneTimeCode(new \DateTimeImmutable('+3 hours'));
                $this->em->flush();

                // 14.4 Recupero password: link monouso all'indirizzo dell'utente.
                $mailer->passwordRecovery(
                    (string) $user->getEmail(),
                    $user->getFullName(),
                    (string) $user->getOneTimeCode(),
                    $user->getExpirationOneTimeCode(),
                );
            }

            // Messaggio generico (evita user enumeration)
            $this->addFlash('notice_success', 'Se l\'indirizzo è registrato, riceverai un\'email con le istruzioni per reimpostare la password.');

            return $this->redirectToRoute('login');
        }

        return $this->render('security/password_recover.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    // ================= 4. PAGINA CREAZIONE PASSWORD =================

    #[Route('/crea-password/{code}', name: 'password_create', methods: ['GET', 'POST'], requirements: ['code' => '[a-f0-9]{32}'])]
    public function passwordCreate(string $code, Request $request): Response
    {
        $user = $this->users->findOneBy(['oneTimeCode' => $code]);
        if (!$user instanceof User || !$user->isOneTimeCodeValid($code)) {
            $this->addFlash('notice_danger', 'Il link non è valido o è scaduto. Richiedi un nuovo recupero password.');

            return $this->redirectToRoute('login');
        }

        // 4.1 Le regole di robustezza stanno nel validator (config/validator/Model/PasswordCreate.yml).
        $form = $this->formFactory->createNamed('password_create', PasswordCreateType::class, new PasswordCreate(), [
            'action' => $this->generateUrl('password_create', ['code' => $code]),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($this->hasher->hashPassword($user, (string) $form->getData()->getPassword()));
            $user->setOneTimeCode(null);
            $user->setExpirationOneTimeCode(null);
            $this->em->flush();

            $this->addFlash('notice_success', 'Password impostata con successo. Ora puoi accedere.');

            return $this->redirectToRoute('login');
        }

        return $this->render('security/password_create.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    // ================= helper =================

    /** Form "Recupera password": la pagina dedicata e il modale della pagina di accesso inviano entrambi a password_recover. */
    private function recoverForm(): FormInterface
    {
        return $this->formFactory->createNamed('password_recover', PasswordRecoverType::class, new PasswordRecover(), [
            'action' => $this->generateUrl('password_recover'),
        ]);
    }

    private function redirectByRole(): Response
    {
        return match (true) {
            $this->isGranted('ROLE_ADMIN')  => $this->redirectToRoute('admin_index'),
            $this->isGranted('ROLE_NOTARY'),
            $this->isGranted('ROLE_NOTARY_STAFF') => $this->redirectToRoute('notary_index'),
            $this->isGranted('ROLE_USER')   => $this->redirectToRoute('agency_index'),
            default                         => $this->redirectToRoute('homepage'),
        };
    }
}
