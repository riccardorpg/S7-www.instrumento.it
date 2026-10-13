<?php

namespace App\Entity\Slave;

use App\Entity\Trait\ActiveTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampsTrait;
use App\Repository\Slave\CustomerRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * 11. Cliente dell'agenzia: l'anagrafica di chi compra o vende in una pratica.
 * Vive nel DB dell'agenzia (slave) ed è riutilizzabile su più pratiche, dove compare
 * come acquirente (`buyerCustomer`) o venditore (`sellerCustomer`) di {@see Practice}.
 */
#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'eb_s_customer')]
#[ORM\Index(name: 'idx_customer_fiscal_code', columns: ['fiscal_code'])]
#[ORM\HasLifecycleCallbacks]
class Customer
{
    use IdTrait;
    use ActiveTrait;
    use TimestampsTrait;

    // ---------- 11.2.1 Dati anagrafici ----------

    #[ORM\Column(name: 'name', type: 'string', length: 190)]
    private string $name = '';

    #[ORM\Column(name: 'surname', type: 'string', length: 190)]
    private string $surname = '';

    #[ORM\Column(name: 'birth_place', type: 'string', length: 190, nullable: true)]
    private ?string $birthPlace = null;

    #[ORM\Column(name: 'birth_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $birthDate = null;

    #[ORM\Column(name: 'address', type: 'string', length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(name: 'city', type: 'string', length: 190, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(name: 'zip', type: 'string', length: 10, nullable: true)]
    private ?string $zip = null;

    #[ORM\Column(name: 'email', type: 'string', length: 190, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(name: 'phone', type: 'string', length: 40, nullable: true)]
    private ?string $phone = null;

    // ---------- 11.2.2 Dati fiscali ----------

    #[ORM\Column(name: 'fiscal_code', type: 'string', length: 32, nullable: true)]
    private ?string $fiscalCode = null;

    /** Partita IVA, se il cliente opera come impresa o professionista. */
    #[ORM\Column(name: 'vat_number', type: 'string', length: 20, nullable: true)]
    private ?string $vatNumber = null;

    #[ORM\Column(name: 'pec', type: 'string', length: 190, nullable: true)]
    private ?string $pec = null;

    /** Codice destinatario per la fatturazione elettronica. */
    #[ORM\Column(name: 'sdi', type: 'string', length: 7, nullable: true)]
    private ?string $sdi = null;

    #[ORM\Column(name: 'notes', type: 'text', nullable: true)]
    private ?string $notes = null;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSurname(): string
    {
        return $this->surname;
    }

    public function setSurname(string $surname): static
    {
        $this->surname = $surname;

        return $this;
    }

    public function getFullName(): string
    {
        return trim($this->name . ' ' . $this->surname);
    }

    public function getBirthPlace(): ?string
    {
        return $this->birthPlace;
    }

    public function setBirthPlace(?string $birthPlace): static
    {
        $this->birthPlace = $birthPlace;

        return $this;
    }

    public function getBirthDate(): ?\DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function setBirthDate(?\DateTimeImmutable $birthDate): static
    {
        $this->birthDate = $birthDate;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getZip(): ?string
    {
        return $this->zip;
    }

    public function setZip(?string $zip): static
    {
        $this->zip = $zip;

        return $this;
    }

    /** Indirizzo su una riga, per gli elenchi (11.1.4). */
    public function getFullAddress(): string
    {
        $parts = array_filter([
            $this->address,
            trim(($this->zip ?? '') . ' ' . ($this->city ?? '')),
        ], static fn (?string $p) => $p !== null && trim($p) !== '');

        return implode(', ', $parts);
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

    public function getFiscalCode(): ?string
    {
        return $this->fiscalCode;
    }

    public function setFiscalCode(?string $fiscalCode): static
    {
        $this->fiscalCode = $fiscalCode === null ? null : mb_strtoupper($fiscalCode);

        return $this;
    }

    public function getVatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function setVatNumber(?string $vatNumber): static
    {
        $this->vatNumber = $vatNumber;

        return $this;
    }

    public function getPec(): ?string
    {
        return $this->pec;
    }

    public function setPec(?string $pec): static
    {
        $this->pec = $pec;

        return $this;
    }

    public function getSdi(): ?string
    {
        return $this->sdi;
    }

    public function setSdi(?string $sdi): static
    {
        $this->sdi = $sdi === null ? null : mb_strtoupper($sdi);

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function __toString(): string
    {
        return $this->getFullName();
    }
}
