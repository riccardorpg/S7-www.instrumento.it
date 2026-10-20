<?php

namespace App\Entity;

use App\Entity\Trait\ActiveTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampsTrait;
use App\Repository\ExternalAccountRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Account esterno (sez. 8): referente esterno collegato a un cliente-studio (Company).
 */
#[ORM\Entity(repositoryClass: ExternalAccountRepository::class)]
#[ORM\Table(name: 'eb_external_account')]
#[ORM\HasLifecycleCallbacks]
class ExternalAccount
{
    use IdTrait;
    use ActiveTrait;
    use TimestampsTrait;

    #[ORM\Column(name: 'name', type: 'string', length: 128)]
    private ?string $name = null;

    #[ORM\Column(name: 'surname', type: 'string', length: 128)]
    private ?string $surname = null;

    #[ORM\Column(name: 'email', type: 'string', length: 191)]
    private ?string $email = null;

    #[ORM\Column(name: 'phone', type: 'string', length: 32, nullable: true)]
    private ?string $phone = null;

    /** 8.1.5 Studio notarile di riferimento = cliente (Company). */
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSurname(): ?string
    {
        return $this->surname;
    }

    public function setSurname(?string $surname): static
    {
        $this->surname = $surname;

        return $this;
    }

    public function getFullName(): string
    {
        return trim(($this->name ?? '') . ' ' . ($this->surname ?? ''));
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;

        return $this;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): static
    {
        $this->company = $company;

        return $this;
    }

    /** Nessuna dipendenza: sempre eliminabile. */
    public function getCanDelete(): bool
    {
        return true;
    }

    public function __toString(): string
    {
        return $this->getFullName();
    }
}
