<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Chi può entrare, dopo che e-mail e password sono giuste (e a ogni ripristino da "resta collegato"):
 *  - l'utente dev'essere attivo;
 *  - lo staff di un'agenzia entra solo se l'agenzia è attiva e ha una licenza valida
 *    (assegnata, attiva, non scaduta), come in eaml.
 */
class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isActive()) {
            throw new CustomUserMessageAccountStatusException('Il tuo account è disattivato: contatta l\'amministratore.');
        }

        if ($user->isAgencyUser()) {
            $company = $user->getCompany();
            if ($company === null || !$company->isActive()) {
                throw new CustomUserMessageAccountStatusException('La tua agenzia non è attiva: contatta l\'amministratore.');
            }
            if (!$company->hasValidLicense()) {
                throw new CustomUserMessageAccountStatusException('La licenza della tua agenzia è scaduta o non attiva: contatta l\'amministratore.');
            }
        }
    }

    public function checkPostAuth(UserInterface $user, mixed $token = null): void
    {
    }
}
