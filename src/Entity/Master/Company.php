<?php

namespace App\Entity\Master;

use App\Entity\Trait\ActiveTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampsTrait;
use App\Repository\Master\CompanyRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cliente (= agenzia/studio) sul DB master.
 * $dbName è il database slave dedicato, verso cui DynamicConnection ri-punta la
 * connessione 'slave' a runtime.
 */
#[ORM\Entity(repositoryClass: CompanyRepository::class)]
#[ORM\Table(name: 'eb_m_company')]
#[ORM\HasLifecycleCallbacks]
class Company
{
    use IdTrait;
    use ActiveTrait;
    use TimestampsTrait;

    public const TYPE_COMPANY = 'company';
    public const TYPE_PROFESSIONAL = 'professional';

    #[ORM\Column(name: 'code', type: 'string', length: 64, unique: true)]
    private ?string $code = null;

    /** Ragione sociale oppure Nome e cognome (7.1.4). */
    #[ORM\Column(name: 'name', type: 'string', length: 191)]
    private ?string $name = null;

    #[ORM\Column(name: 'db_name', type: 'string', length: 191, unique: true)]
    private ?string $dbName = null;

    /** 7.1.3 Tipo cliente. */
    #[ORM\Column(name: 'client_type', type: 'string', length: 20, options: ['default' => self::TYPE_COMPANY])]
    private string $clientType = self::TYPE_COMPANY;

    // --- 7.1.9.1 Dati fiscali studio ---
    #[ORM\Column(name: 'vat_number', type: 'string', length: 16, nullable: true)]
    private ?string $vatNumber = null;

    #[ORM\Column(name: 'tax_code', type: 'string', length: 32, nullable: true)]
    private ?string $taxCode = null;

    #[ORM\Column(name: 'address', type: 'string', length: 191, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(name: 'civic', type: 'string', length: 16, nullable: true)]
    private ?string $civic = null;

    // Città e CAP normalizzati (FK verso il sottosistema geo Master).
    #[ORM\ManyToOne(targetEntity: City::class)]
    #[ORM\JoinColumn(name: 'city_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?City $city = null;

    #[ORM\ManyToOne(targetEntity: Zip::class)]
    #[ORM\JoinColumn(name: 'zip_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Zip $zip = null;

    #[ORM\Column(name: 'sdi', type: 'string', length: 7, nullable: true)]
    private ?string $sdi = null;

    #[ORM\Column(name: 'pec', type: 'string', length: 191, nullable: true)]
    private ?string $pec = null;

    // --- 7.1.7 / 7.1.2 Licenza: creata e assegnata dall'admin (vedi LicenseController) ---
    #[ORM\OneToOne(targetEntity: License::class, inversedBy: 'company')]
    #[ORM\JoinColumn(name: 'license_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?License $license = null;

    /**
     * 7.1.8 Pacchetto di spazio aggiuntivo: uno solo per cliente (i pacchetti sono
     * crescenti, si passa a quello più grande). La quota totale è lo spazio compreso
     * nella licenza più il pacchetto; l'occupato si calcola a runtime (CompanyService).
     */
    #[ORM\ManyToOne(targetEntity: SpaceExtra::class)]
    #[ORM\JoinColumn(name: 'space_extra_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SpaceExtra $spaceExtra = null;

    /**
     * 7.1.9.8 Logo dell'agenzia: nome del file dentro la cartella del tenant
     * (vedi CompanyLogoService). Lo carica l'agenzia dalla propria anagrafica.
     */
    #[ORM\Column(name: 'logo', type: 'string', length: 191, nullable: true)]
    private ?string $logo = null;

    // --- 7.1.9.7 Contratto termini e condizioni ---
    #[ORM\Column(name: 'terms_accepted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $termsAcceptedAt = null;

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = $logo;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDbName(): ?string
    {
        return $this->dbName;
    }

    public function setDbName(?string $dbName): static
    {
        $this->dbName = $dbName;

        return $this;
    }

    public function getClientType(): string
    {
        return $this->clientType;
    }

    public function setClientType(string $clientType): static
    {
        $this->clientType = $clientType;

        return $this;
    }

    public function getClientTypeLabel(): string
    {
        return $this->clientType === self::TYPE_PROFESSIONAL ? 'Privato' : 'Azienda';
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

    public function getTaxCode(): ?string
    {
        return $this->taxCode;
    }

    public function setTaxCode(?string $taxCode): static
    {
        $this->taxCode = $taxCode;

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

    public function getCity(): ?City
    {
        return $this->city;
    }

    public function setCity(?City $city): static
    {
        $this->city = $city;

        return $this;
    }

    public function getZip(): ?Zip
    {
        return $this->zip;
    }

    public function setZip(?Zip $zip): static
    {
        $this->zip = $zip;

        return $this;
    }

    /** Provincia derivata dalla città (come DualMoto). */
    public function getProvince(): ?Province
    {
        return $this->city?->getProvince();
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

    public function getLicense(): ?License
    {
        return $this->license;
    }

    /**
     * Assegna (o toglie) la licenza, tenendo allineato il lato inverso. È l'unico punto in cui
     * una licenza entra o esce da un cliente:
     * - quella che arriva, se non è ancora partita, parte oggi (un anno, License::start());
     * - quella che esce torna libera, senza partenza né scadenza (License::release()).
     */
    public function setLicense(?License $license): static
    {
        if ($this->license !== null && $this->license !== $license) {
            $this->license->setCompany(null)->release();
        }
        if ($license !== null && $license->getStartDate() === null) {
            $license->start();
        }
        $license?->setCompany($this);
        $this->license = $license;

        return $this;
    }

    public function getSpaceExtra(): ?SpaceExtra
    {
        return $this->spaceExtra;
    }

    public function setSpaceExtra(?SpaceExtra $spaceExtra): static
    {
        $this->spaceExtra = $spaceExtra;

        return $this;
    }

    // --- Valori derivati da licenza e pacchetti (sola lettura) ---

    public function getLicenseTypeLabel(): string
    {
        return $this->license?->getType()?->getLabel() ?? 'Nessuna';
    }

    public function getLicenseExpiresAt(): ?\DateTimeImmutable
    {
        return $this->license?->getExpirationDate();
    }

    /** Spazio compreso nella licenza, in MB. */
    public function getStorageIncludedMb(): int
    {
        return $this->license?->getType()?->getSpaceIncluded() ?? 0;
    }

    /** Spazio del pacchetto aggiuntivo, in MB. */
    public function getStorageExtraMb(): int
    {
        return $this->spaceExtra?->getSpace() ?? 0;
    }

    /** Quota totale: licenza + pacchetto, in MB. */
    public function getStorageQuotaMb(): int
    {
        return $this->getStorageIncludedMb() + $this->getStorageExtraMb();
    }

    public function getTermsAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->termsAcceptedAt;
    }

    public function setTermsAcceptedAt(?\DateTimeImmutable $termsAcceptedAt): static
    {
        $this->termsAcceptedAt = $termsAcceptedAt;

        return $this;
    }

    public function isTermsAccepted(): bool
    {
        return $this->termsAcceptedAt !== null;
    }

    public function isDemo(): bool
    {
        return $this->license?->isDemo() ?? false;
    }

    /** Ha una licenza valida: assegnata, attiva per l'amministratore e non scaduta (License::isValid). */
    public function hasValidLicense(): bool
    {
        return $this->license !== null && $this->license->isValid();
    }

    /**
     * L'agenzia si può usare (come lo studio "attivo" di eaml): cliente non sospeso e licenza
     * valida. Senza, il suo staff e i notai non entrano.
     */
    public function isOperational(): bool
    {
        return $this->isActive() && $this->hasValidLicense();
    }

    /** Giorni residui alla scadenza licenza (null se senza licenza, negativo se scaduta). */
    public function getDaysToExpiry(?\DateTimeImmutable $now = null): ?int
    {
        return $this->license?->getDaysToExpiry($now);
    }

    /**
     * Un cliente è eliminabile solo se sospeso (non attivo): va prima disattivato.
     * Evita la cancellazione accidentale di clienti operativi (con DB e dati).
     */
    public function getCanDelete(): bool
    {
        return !$this->isActive();
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
