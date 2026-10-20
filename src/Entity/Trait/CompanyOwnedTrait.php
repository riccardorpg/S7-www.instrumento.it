<?php

namespace App\Entity\Trait;

use App\Entity\Company;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dato di un'agenzia: la FK `company_id` lo lega al suo proprietario. Va con
 * {@see \App\Entity\CompanyOwnedInterface}, che il filtro Doctrine `company` usa per
 * limitare le query dello staff alla propria agenzia.
 */
trait CompanyOwnedTrait
{
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Company $company = null;

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): static
    {
        $this->company = $company;

        return $this;
    }
}
