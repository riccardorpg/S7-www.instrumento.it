<?php

namespace App\Entity\Master;

use App\Entity\Trait\ActiveTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampsTrait;
use App\Entity\Trait\UserSecurityTrait;
use App\Repository\Master\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Utente del DB centrale (master): ROLE_ADMIN e ROLE_NOTARY.
 * Creato a mano / tramite comando app:create-user (nessuna registrazione pubblica).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'eb_m_user')]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    use IdTrait;
    use UserSecurityTrait;
    use ActiveTrait;
    use TimestampsTrait;

    /**
     * Agenzie (clienti) che il notaio può vedere/gestire. Rilevante solo per ROLE_NOTARY.
     * @var Collection<int, Company>
     */
    #[ORM\ManyToMany(targetEntity: Company::class)]
    #[ORM\JoinTable(name: 'eb_m_notary_company')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'company_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $companies;

    public function __construct()
    {
        $this->companies = new ArrayCollection();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'ROLE_ADMIN';
    }

    public function isNotary(): bool
    {
        return $this->role === 'ROLE_NOTARY';
    }

    /** @return Collection<int, Company> */
    public function getCompanies(): Collection
    {
        return $this->companies;
    }

    public function addCompany(Company $company): static
    {
        if (!$this->companies->contains($company)) {
            $this->companies->add($company);
        }

        return $this;
    }

    public function removeCompany(Company $company): static
    {
        $this->companies->removeElement($company);

        return $this;
    }

    public function hasCompany(Company $company): bool
    {
        return $this->companies->contains($company);
    }

    public function __toString(): string
    {
        return $this->getFullName();
    }
}
