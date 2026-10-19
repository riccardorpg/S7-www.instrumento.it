<?php

namespace App\Service;

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
}
