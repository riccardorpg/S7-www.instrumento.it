<?php

namespace App\Entity\Master;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampsTrait;
use App\Repository\Master\DemoRequestRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Richiesta demo inviata dal form pubblico "Richiedi la demo per 30 giorni".
 * Le richieste ancora da evadere (status=new) sono gli allarmi "Nuove richieste in
 * arrivo" della scrivania admin (6.1.1); da lì l'admin le converte in cliente
 * (status=converted, con $company valorizzata) oppure le scarta (status=rejected).
 */
#[ORM\Entity(repositoryClass: DemoRequestRepository::class)]
#[ORM\Table(name: 'eb_m_demo_request')]
#[ORM\HasLifecycleCallbacks]
class DemoRequest
{
    use IdTrait;
    use TimestampsTrait;

    /** Da evadere: è l'allarme in scrivania. */
    public const STATUS_NEW = 'new';
    /** Evasa: è diventata un cliente ($company). */
    public const STATUS_CONVERTED = 'converted';
    /** Scartata (spam, doppione, non interessata): resta in archivio. */
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Column(name: 'account_type', type: 'string', length: 20)]
    private string $accountType = Company::TYPE_COMPANY;

    #[ORM\Column(name: 'email', type: 'string', length: 191)]
    private ?string $email = null;

    /** Ragione sociale oppure nome e cognome. */
    #[ORM\Column(name: 'business_name', type: 'string', length: 191, nullable: true)]
    private ?string $businessName = null;

    #[ORM\Column(name: 'address', type: 'string', length: 191, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(name: 'civic', type: 'string', length: 16, nullable: true)]
    private ?string $civic = null;

    #[ORM\Column(name: 'city', type: 'string', length: 128, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(name: 'zip', type: 'string', length: 5, nullable: true)]
    private ?string $zip = null;

    #[ORM\Column(name: 'sdi', type: 'string', length: 7, nullable: true)]
    private ?string $sdi = null;

    #[ORM\Column(name: 'pec', type: 'string', length: 191, nullable: true)]
    private ?string $pec = null;

    #[ORM\Column(name: 'status', type: 'string', length: 20, options: ['default' => self::STATUS_NEW])]
    private string $status = self::STATUS_NEW;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    /** E-mail dell'admin che ha evaso/scartato la richiesta (per tracciabilità). */
    #[ORM\Column(name: 'processed_by', type: 'string', length: 191, nullable: true)]
    private ?string $processedBy = null;

    /** Cliente creato da questa richiesta (solo se status=converted). */
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    public function getAccountType(): string
    {
        return $this->accountType;
    }

    public function setAccountType(string $accountType): static
    {
        $this->accountType = $accountType;

        return $this;
    }

    public function getAccountTypeLabel(): string
    {
        return $this->accountType === Company::TYPE_PROFESSIONAL ? 'Privato' : 'Azienda';
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

    public function getBusinessName(): ?string
    {
        return $this->businessName;
    }

    public function setBusinessName(?string $businessName): static
    {
        $this->businessName = $businessName;

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

    public function getCivic(): ?string
    {
        return $this->civic;
    }

    public function setCivic(?string $civic): static
    {
        $this->civic = $civic;

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

    public function getSdi(): ?string
    {
        return $this->sdi;
    }

    public function setSdi(?string $sdi): static
    {
        $this->sdi = $sdi;

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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_CONVERTED => 'Evasa',
            self::STATUS_REJECTED => 'Scartata',
            default => 'Da evadere',
        };
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getProcessedBy(): ?string
    {
        return $this->processedBy;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function isNew(): bool
    {
        return $this->status === self::STATUS_NEW;
    }

    public function isProcessed(): bool
    {
        return $this->status !== self::STATUS_NEW;
    }

    /** Richiesta evasa: è nato il cliente $company. */
    public function markConverted(Company $company, ?string $by = null): static
    {
        $this->status = self::STATUS_CONVERTED;
        $this->company = $company;
        $this->processedAt = new \DateTimeImmutable();
        $this->processedBy = $by;

        return $this;
    }

    /** Richiesta scartata: resta in archivio, senza cliente collegato. */
    public function markRejected(?string $by = null): static
    {
        $this->status = self::STATUS_REJECTED;
        $this->company = null;
        $this->processedAt = new \DateTimeImmutable();
        $this->processedBy = $by;

        return $this;
    }

    /** Rimette una richiesta scartata tra quelle da evadere. */
    public function markNew(): static
    {
        $this->status = self::STATUS_NEW;
        $this->company = null;
        $this->processedAt = null;
        $this->processedBy = null;

        return $this;
    }

    public function __toString(): string
    {
        return (string) ($this->businessName ?: $this->email);
    }
}
