<?php

namespace App\Controller\Agency\Trait;

use App\Entity\Company;
use App\Entity\User;

/**
 * Agenzia dell'utente dello staff collegato: è la proprietaria di tutto ciò che si crea
 * dall'area agenzia. Da usare in un AbstractController.
 */
trait AgencyContextTrait
{
    private function company(): Company
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isAgencyUser() || $user->getCompany() === null) {
            throw $this->createAccessDeniedException('Contesto agenzia non disponibile.');
        }

        return $user->getCompany();
    }
}
