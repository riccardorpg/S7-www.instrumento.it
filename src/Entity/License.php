<?php

namespace App\Entity;

use App\Entity\Trait\IdTrait;
use App\Repository\LicenseRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Licenza creata dall'admin e poi assegnata a un cliente (Company::$license), come in eaml.software.
 * Finché non è assegnata è "libera": non ha data di partenza né scadenza (il suo anno non è
 * ancora cominciato) e può essere eliminata. Il periodo parte dalla data di attivazione (start()),
 * che l'admin sceglie quando la assegna, la cambia o la rinnova. Scaduta resta al cliente, non
 * valida, finché non viene rinnovata; revocata torna libera.
 */
#[ORM\Entity(repositoryClass: LicenseRepository::class)]
#[ORM\Table(name: 'eb_license')]
class License
{
    use IdTrait;

    #[ORM\Column(name: 'creation_date', type: 'date_immutable')]
    private ?\DateTimeImmutable $creationDate = null;

    /** Data di partenza dell'utilizzo: il giorno in cui la licenza è stata assegnata al cliente. */
    #[ORM\Column(name: 'start_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $startDate = null;

    /** Scadenza: un periodo dalla data di partenza (null finché la licenza è libera). */
    #[ORM\Column(name: 'expiration_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $expirationDate = null;

    #[ORM\ManyToOne(targetEntity: LicenseType::class)]
    #[ORM\JoinColumn(name: 'type_id', referencedColumnName: 'id', nullable: false)]
    private ?LicenseType $type = null;

    /**
     * 14.6 Quando è partito l'avviso di scadenza: evita di ripeterlo a ogni esecuzione
     * del comando. Azzerato quando cambia la scadenza.
     */
    #[ORM\Column(name: 'notice_sent_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $noticeSentAt = null;

    /** Interruttore dell'amministratore: una licenza disattivata non è valida anche se non è scaduta. */
    #[ORM\Column(name: 'is_admin_active', type: 'boolean', options: ['default' => true])]
    private bool $isAdminActive = true;

    #[ORM\OneToOne(targetEntity: Company::class, mappedBy: 'license')]
    private ?Company $company = null;

    /** Durata di una licenza, dalla partenza. */
    public const PERIOD = '+1 year';

    /** Nuova licenza: creata oggi, libera (il periodo parte all'assegnazione). */
    public function __construct()
    {
        $this->creationDate = new \DateTimeImmutable('today');
    }

    /**
     * Attivazione (partenza dell'utilizzo): all'assegnazione, al cambio e al rinnovo. Da quel
     * giorno conta un periodo (License::PERIOD); di default oggi.
     */
    public function start(?\DateTimeImmutable $from = null): static
    {
        $this->startDate = $from ?? new \DateTimeImmutable('today');

        return $this->setExpirationDate($this->startDate->modify(self::PERIOD));
    }

    /** Torna libera (revoca): niente partenza né scadenza, alla prossima assegnazione riparte da capo. */
    public function release(): static
    {
        $this->startDate = null;
        $this->expirationDate = null;
        $this->noticeSentAt = null;
        $this->isAdminActive = true;

        return $this;
    }

    /** Valida (come in eaml): assegnata, attiva per l'amministratore e non scaduta. */
    public function isValid(): bool
    {
        return $this->startDate !== null && $this->isAdminActive && !$this->isExpired();
    }

    public function isAdminActive(): bool
    {
        return $this->isAdminActive;
    }

    public function setAdminActive(bool $isAdminActive): static
    {
        $this->isAdminActive = $isAdminActive;

        return $this;
    }

    public function getCreationDate(): ?\DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function setCreationDate(\DateTimeImmutable $creationDate): static
    {
        $this->creationDate = $creationDate;

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getExpirationDate(): ?\DateTimeImmutable
    {
        return $this->expirationDate;
    }

    public function setExpirationDate(?\DateTimeImmutable $expirationDate): static
    {
        // Nuova scadenza: l'avviso 14.6 potrà ripartire per il nuovo periodo.
        if ($this->expirationDate != $expirationDate) {
            $this->noticeSentAt = null;
        }
        $this->expirationDate = $expirationDate;

        return $this;
    }

    public function getType(): ?LicenseType
    {
        return $this->type;
    }

    public function setType(LicenseType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getNoticeSentAt(): ?\DateTimeImmutable
    {
        return $this->noticeSentAt;
    }

    public function setNoticeSentAt(?\DateTimeImmutable $noticeSentAt): static
    {
        $this->noticeSentAt = $noticeSentAt;

        return $this;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    /** Lato inverso: va impostato solo da Company::setLicense(). */
    public function setCompany(?Company $company): static
    {
        $this->company = $company;

        return $this;
    }

    public function isFree(): bool
    {
        return $this->company === null;
    }

    public function isDemo(): bool
    {
        return $this->type?->isDemo() ?? false;
    }

    /** Giorni residui alla scadenza (negativo se scaduta, null se non è ancora partita). */
    public function getDaysToExpiry(?\DateTimeImmutable $now = null): ?int
    {
        if ($this->expirationDate === null) {
            return null;
        }
        $now ??= new \DateTimeImmutable('today');

        return (int) $now->diff($this->expirationDate)->format('%r%a');
    }

    public function isExpired(): bool
    {
        $days = $this->getDaysToExpiry();

        return $days !== null && $days < 0;
    }

    /** Etichetta per le select: "Base · scade il 31/12/2026" oppure, se libera, "Base · creata il 13/10/2026". */
    public function getInfo(): string
    {
        return $this->type?->getLabel() . ($this->expirationDate !== null
            ? ' · scade il ' . $this->expirationDate->format('d/m/Y')
            : ' · creata il ' . $this->creationDate?->format('d/m/Y'));
    }

    public function __toString(): string
    {
        return $this->getInfo();
    }
}
