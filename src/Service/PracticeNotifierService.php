<?php

namespace App\Service;

use App\Entity\Master\Company;
use App\Entity\Slave\Practice;

/**
 * 12.3.2.7 / 14.7 Notifica di aggiornamento file su una pratica.
 *
 * Qui vive solo la scelta dei destinatari possibili: la composizione e l'invio passano
 * da {@see MailerService}, come tutte le altre notifiche del punto 14.
 */
class PracticeNotifierService
{
    public function __construct(
        private readonly MailerService $mailer,
        private readonly CompanyService $companies,
    ) {
    }

    /**
     * Destinatari possibili per una pratica: le parti, il notaio assegnato e lo staff
     * abilitato. Chiave = email, valore = etichetta mostrata nella tendina.
     *
     * @return array<string, string>
     */
    public function recipientsFor(Practice $practice): array
    {
        $recipients = [];

        foreach ([['Venditore', $practice->getSeller()], ['Acquirente', $practice->getBuyer()]] as [$role, $customer]) {
            $email = $customer?->getEmail();
            if ($email) {
                $recipients[$email] = sprintf('%s (%s)', $customer->getFullName(), $role);
            }
        }

        if ($practice->getNotaryEmail()) {
            $recipients[$practice->getNotaryEmail()] = $practice->getNotaryEmail() . ' (Notaio)';
        }

        foreach ($practice->getStaff() as $member) {
            if ($member->getEmail()) {
                $recipients[$member->getEmail()] = $member->getFullName() . ' (Staff)';
            }
        }

        return $recipients;
    }

    /**
     * Invia la notifica. Ritorna false se il trasporto rifiuta il messaggio: la
     * schermata lo segnala invece di far fallire la richiesta.
     */
    public function notifyFileUpdate(Practice $practice, string $to, string $message, string $senderName): bool
    {
        return $this->mailer->fileUpdate($practice, $to, $message, $senderName);
    }

    /**
     * 17.1.1.3 Chi avvisare quando il notaio mette delle note: gli agenti che seguono la
     * pratica (lo staff abilitato). Se non è stato abilitato nessuno la pratica è in mano
     * agli amministratori dell'agenzia, quindi si ripiega sul contatto principale.
     *
     * @return string[]
     */
    public function agentEmailsFor(Practice $practice, Company $company): array
    {
        $emails = [];
        foreach ($practice->getStaff() as $member) {
            if ($member->getEmail()) {
                $emails[mb_strtolower($member->getEmail())] = true;
            }
        }

        if ($emails === []) {
            $fallback = $this->companies->getPrimaryContactEmail($company);
            if ($fallback !== null && $fallback !== '') {
                $emails[mb_strtolower($fallback)] = true;
            }
        }

        return array_keys($emails);
    }

    /**
     * 17.1.1.3 Avvisa gli agenti che il notaio ha inserito delle note. Ritorna quante
     * notifiche sono partite: chi chiama lo dice all'utente.
     *
     * @param string[] $recipients
     */
    public function notifyNotaryNotes(Practice $practice, array $recipients, string $notaryName): int
    {
        $sent = 0;
        foreach ($recipients as $to) {
            if ($this->mailer->notaryNotes($practice, $to, $notaryName)) {
                ++$sent;
            }
        }

        return $sent;
    }
}
