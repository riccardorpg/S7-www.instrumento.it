<?php

namespace App\Entity;

/**
 * Entity che appartiene a un'agenzia (colonna `company_id`, vedi CompanyOwnedTrait).
 * Per gli utenti dello staff il filtro Doctrine {@see \App\Doctrine\Filter\CompanyFilter}
 * nasconde le righe delle altre agenzie.
 */
interface CompanyOwnedInterface
{
    public function getCompany(): ?Company;

    public function setCompany(?Company $company): static;
}
