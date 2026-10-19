<?php

namespace App\Service;

use App\Entity\Master\Company;
use App\Entity\Master\DemoRequest;
use App\Entity\Slave\Practice;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * 14. Notifiche via e-mail della piattaforma, tutte con TemplatedEmail e i template
 * sotto templates/email (stile Tillomeditalia).
 *
 * Ogni metodo ritorna true/false: se il trasporto rifiuta il messaggio la richiesta non
 * fallisce, è chi chiama a decidere cosa dire all'utente. Con MAILER_DSN su `null://null`
 * l'invio va a vuoto senza errori, quindi il codice è già pronto per un DSN vero.
 */
class MailerService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly ParameterBagInterface $params,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** 14.1 Nuova richiesta di contatto: avvisa l'indirizzo interno. */
    public function contactRequest(string $name, string $surname, string $email, string $message): bool
    {
        return $this->send(
            $this->param('email_admin'),
            $this->param('object_contact_request'),
            'email/contact_request.html.twig',
            // `email` è una variabile riservata di TemplatedEmail: qui serve un altro nome.
            ['name' => $name, 'surname' => $surname, 'senderEmail' => $email, 'message' => $message],
            replyTo: $email,
        );
    }

    /** 14.2 Nuova richiesta demo: avvisa l'indirizzo interno. */
    public function demoRequest(DemoRequest $request): bool
    {
        return $this->send(
            $this->param('email_admin'),
            $this->param('object_demo_request'),
            'email/demo_request.html.twig',
            ['request' => $request],
            replyTo: $request->getEmail(),
        );
    }

    /**
     * 14.3 Creazione della password: link monouso all'utente.
     * $companyCode: codice dell'agenzia per gli utenti slave, null per admin e notai.
     */
    public function passwordCreate(string $to, string $name, string $code, ?string $companyCode, ?\DateTimeImmutable $expiresAt): bool
    {
        return $this->send(
            $to,
            $this->param('object_password_create'),
            'email/security/password_create.html.twig',
            [
                'name' => $name,
                'link' => $this->passwordLink($code, $companyCode),
                'loginUrl' => $this->loginUrl($companyCode),
                'expiresAt' => $expiresAt,
            ],
        );
    }

    /** 14.4 Recupero password: stesso link, testo legato alla richiesta dell'utente. */
    public function passwordRecovery(string $to, string $name, string $code, ?string $companyCode, ?\DateTimeImmutable $expiresAt): bool
    {
        return $this->send(
            $to,
            $this->param('object_recovery'),
            'email/security/password_recovery.html.twig',
            [
                'name' => $name,
                'link' => $this->passwordLink($code, $companyCode),
                'expiresAt' => $expiresAt,
            ],
        );
    }

    /** 14.5 Sospensione della Demo: la prova è finita e l'accesso è sospeso. */
    public function demoSuspended(Company $company, string $to): bool
    {
        return $this->send(
            $to,
            $this->param('object_demo_suspended'),
            'email/client/demo_suspended.html.twig',
            ['company' => $company, 'contactEmail' => $this->param('email_support')],
        );
    }

    /** 14.6 Licenza in scadenza: avviso coi giorni residui. */
    public function licenseExpiring(Company $company, string $to, int $days): bool
    {
        return $this->send(
            $to,
            $this->param('object_license_expiring'),
            'email/client/license_expiring.html.twig',
            ['company' => $company, 'days' => $days, 'contactEmail' => $this->param('email_support')],
        );
    }

    /** 14.7 Notifica caricamento file su una pratica. */
    public function fileUpdate(Practice $practice, string $to, string $message, string $senderName): bool
    {
        return $this->send(
            $to,
            sprintf($this->param('object_file_update'), $practice->getNumber()),
            'email/practice/file_update.html.twig',
            ['practice' => $practice, 'message' => $message, 'senderName' => $senderName],
        );
    }

    /** 14.8 Invito ad utilizzare la piattaforma: primo accesso di un nuovo utente. */
    public function invite(string $to, string $name, Company $company, string $code, ?string $companyCode): bool
    {
        return $this->send(
            $to,
            $this->param('object_invite'),
            'email/account/invite.html.twig',
            [
                'name' => $name,
                'companyName' => $company->getName(),
                'link' => $this->passwordLink($code, $companyCode),
                'licenseExpiresAt' => $company->getLicenseExpiresAt(),
                'isDemo' => $company->isDemo(),
            ],
        );
    }

    /** 14.9 Contratto termini e condizioni accettato, con copia all'indirizzo dedicato. */
    public function termsAccepted(Company $company, string $to): bool
    {
        return $this->send(
            $to,
            $this->param('object_terms_accepted'),
            'email/client/terms_accepted.html.twig',
            ['company' => $company],
            cc: $this->param('email_contracts'),
        );
    }

    // ================= interno =================

    /**
     * Link di creazione password. Per gli utenti agenzia serve anche il codice cliente,
     * che dice a quale DB puntare (vedi SecurityController::passwordCreate()).
     */
    private function passwordLink(string $code, ?string $companyCode): string
    {
        $params = ['code' => $code];
        if ($companyCode !== null && $companyCode !== '') {
            $params['c'] = $companyCode;
        }

        return $this->urls->generate('password_create', $params, UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** Pagina di accesso: quella dedicata all'agenzia se il codice c'è, altrimenti lo staff. */
    private function loginUrl(?string $companyCode): string
    {
        if ($companyCode !== null && $companyCode !== '') {
            return $this->urls->generate('login_dedicated', ['code' => $companyCode], UrlGeneratorInterface::ABSOLUTE_URL);
        }

        return $this->urls->generate('login_staff', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function param(string $name): string
    {
        return (string) $this->params->get($name);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(
        string $to,
        string $subject,
        string $template,
        array $context,
        ?string $cc = null,
        ?string $replyTo = null,
    ): bool {
        $email = (new TemplatedEmail())
            ->from($this->param('email_noreply'))
            ->to($to)
            ->subject($subject)
            ->htmlTemplate($template)
            ->context($context);

        if ($cc !== null && $cc !== '') {
            $email->cc($cc);
        }
        if ($replyTo !== null && $replyTo !== '') {
            $email->replyTo($replyTo);
        }

        try {
            $this->mailer->send($email);

            return true;
        } catch (TransportExceptionInterface) {
            return false;
        }
    }
}
