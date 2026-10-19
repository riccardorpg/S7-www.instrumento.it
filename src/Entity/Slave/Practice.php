<?php

namespace App\Entity\Slave;

use App\Entity\Trait\ActiveTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampsTrait;
use App\Repository\Slave\PracticeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * 17.1.1.1 Pratica (Practice): vive nel DB dell'agenzia (slave). Inserita dall'agenzia,
 * gestita dal notaio (verifica documenti, completamento, archiviazione).
 */
#[ORM\Entity(repositoryClass: PracticeRepository::class)]
#[ORM\Table(name: 'eb_s_practice')]
#[ORM\HasLifecycleCallbacks]
class Practice
{
    use IdTrait;
    use ActiveTrait;
    use TimestampsTrait;

    // 17.1.1.2 Stati del ciclo di vita. Una pratica completata è già archiviabile: non c'è uno
    // stato "archiviabile" a parte (le vecchie le ha portate a "completata" la migration slave 20261019).
    public const STATUS_OPEN = 'open';
    public const STATUS_COMPLETED = 'completed';

    // 12.3.5 Archiviata: stato finale, oltre a quelli del ciclo notaio.
    public const STATUS_ARCHIVED = 'archived';

    /** @var array<string, string> Stati con la loro etichetta, nell'ordine del ciclo di vita. */
    public const STATUSES = [
        self::STATUS_OPEN => 'Aperta',
        self::STATUS_COMPLETED => 'Completata',
        self::STATUS_ARCHIVED => 'Archiviata',
    ];

    /** 12.1.6 Numero/codice pratica. */
    #[ORM\Column(name: 'number', type: 'string', length: 60)]
    private string $number = '';

    /**
     * 12.2.4 Tipo di pratica: con mutuo / senza mutuo. È il flag che decide quali tipi
     * di documento entrano nella pratica (13.1.1.3).
     */
    #[ORM\Column(name: 'mortgage', type: 'boolean', options: ['default' => false])]
    private bool $mortgage = false;

    /** Oggetto/immobile della pratica. */
    #[ORM\Column(name: 'subject', type: 'text', nullable: true)]
    private ?string $subject = null;

    /** 11.2.3.7 Indirizzo dell'immobile oggetto della pratica. */
    #[ORM\Column(name: 'address', type: 'string', length: 255, nullable: true)]
    private ?string $address = null;

    /**
     * 12.2.5 Città e CAP scelti dal catalogo geografico del Master.
     *
     * Sono copiati come testo, non come relazione: la pratica sta nel DB dell'agenzia
     * e City/Zip nel master, quindi una FK fra i due database non è possibile. Teniamo
     * anche gli id di origine, che servono a ripopolare il picker in modifica.
     */
    #[ORM\Column(name: 'city', type: 'string', length: 190, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(name: 'zip', type: 'string', length: 10, nullable: true)]
    private ?string $zip = null;

    #[ORM\Column(name: 'city_ref_id', type: 'integer', nullable: true)]
    private ?int $cityRefId = null;

    #[ORM\Column(name: 'zip_ref_id', type: 'integer', nullable: true)]
    private ?int $zipRefId = null;

    #[ORM\Column(name: 'status', type: 'string', length: 20)]
    private string $status = self::STATUS_OPEN;

    /**
     * 12.2.9 / 17.1 Notaio assegnato alla pratica (e-mail dell'utente master): è questa
     * assegnazione a dargli accesso all'agenzia. Null = nessun notaio, pratica non
     * visibile in area notaio.
     */
    #[ORM\Column(name: 'notary_email', type: 'string', length: 190, nullable: true)]
    private ?string $notaryEmail = null;

    /**
     * 11. Le parti sono clienti dell'agenzia (anagrafica riutilizzabile), non più
     * un'anagrafica ricopiata dentro la pratica. RESTRICT: un cliente con pratiche
     * collegate non si può cancellare.
     */
    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'buyer_customer_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Customer $buyer = null;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'seller_customer_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?Customer $seller = null;

    /** 12.2.7 Contrassegno (uno solo per pratica). */
    #[ORM\ManyToOne(targetEntity: PracticeMark::class)]
    #[ORM\JoinColumn(name: 'mark_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PracticeMark $mark = null;

    /**
     * 12.2.6 Tag della pratica (più di uno).
     *
     * @var Collection<int, PracticeTag>
     */
    #[ORM\ManyToMany(targetEntity: PracticeTag::class)]
    #[ORM\JoinTable(name: 'eb_s_practice_tag_map')]
    private Collection $tags;

    /**
     * 12.3.3 Membri dello staff che accedono alla pratica oltre agli amministratori.
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'eb_s_practice_staff')]
    private Collection $staff;

    /**
     * 12.3.2.1 Righe documentali: un tipo di documento previsto per la pratica.
     *
     * @var Collection<int, PracticeDocument>
     */
    #[ORM\OneToMany(mappedBy: 'practice', targetEntity: PracticeDocument::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['priority' => 'ASC', 'id' => 'ASC'])]
    private Collection $practiceDocuments;

    /**
     * 12.3.4 Avvisi (promemoria) sulla pratica.
     *
     * @var Collection<int, PracticeAlert>
     */
    #[ORM\OneToMany(mappedBy: 'practice', targetEntity: PracticeAlert::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['remindAt' => 'ASC'])]
    private Collection $alerts;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
        $this->staff = new ArrayCollection();
        $this->practiceDocuments = new ArrayCollection();
        $this->alerts = new ArrayCollection();
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function setNumber(string $number): static
    {
        $this->number = $number;

        return $this;
    }

    public function isMortgage(): bool
    {
        return $this->mortgage;
    }

    public function setMortgage(bool $mortgage): static
    {
        $this->mortgage = $mortgage;

        return $this;
    }

    /** 12.1.3 Tipo di pratica, in chiaro. */
    public function getTypeLabel(): string
    {
        return $this->mortgage ? 'Con mutuo' : 'Senza mutuo';
    }

    /** 12.2.3 La data di creazione la sceglie l'agenzia nel form di inserimento. */
    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getSubject(): ?string
    {
        return $this->subject;
    }

    public function setSubject(?string $subject): static
    {
        $this->subject = $subject;

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

    public function getCityRefId(): ?int
    {
        return $this->cityRefId;
    }

    public function setCityRefId(?int $cityRefId): static
    {
        $this->cityRefId = $cityRefId;

        return $this;
    }

    public function getZipRefId(): ?int
    {
        return $this->zipRefId;
    }

    public function setZipRefId(?int $zipRefId): static
    {
        $this->zipRefId = $zipRefId;

        return $this;
    }

    /** Indirizzo completo su una riga, per elenchi e archivi. */
    public function getFullAddress(): string
    {
        $parts = array_filter([
            $this->address,
            trim(($this->zip ?? '') . ' ' . ($this->city ?? '')),
        ], static fn (?string $p) => $p !== null && trim($p) !== '');

        return implode(', ', $parts);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStatusLabel(): string
    {
        return self::STATUSES[$this->status] ?? self::STATUSES[self::STATUS_OPEN];
    }

    public function getNotaryEmail(): ?string
    {
        return $this->notaryEmail;
    }

    public function setNotaryEmail(?string $notaryEmail): static
    {
        $this->notaryEmail = $notaryEmail;

        return $this;
    }

    public function getBuyer(): ?Customer
    {
        return $this->buyer;
    }

    public function setBuyer(?Customer $buyer): static
    {
        $this->buyer = $buyer;

        return $this;
    }

    public function getSeller(): ?Customer
    {
        return $this->seller;
    }

    public function setSeller(?Customer $seller): static
    {
        $this->seller = $seller;

        return $this;
    }

    public function getMark(): ?PracticeMark
    {
        return $this->mark;
    }

    public function setMark(?PracticeMark $mark): static
    {
        $this->mark = $mark;

        return $this;
    }

    /** @return Collection<int, PracticeTag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(PracticeTag $tag): static
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function clearTags(): static
    {
        $this->tags->clear();

        return $this;
    }

    /** @return Collection<int, User> */
    public function getStaff(): Collection
    {
        return $this->staff;
    }

    public function addStaff(User $user): static
    {
        if (!$this->staff->contains($user)) {
            $this->staff->add($user);
        }

        return $this;
    }

    public function clearStaff(): static
    {
        $this->staff->clear();

        return $this;
    }

    /** @return Collection<int, PracticeDocument> */
    public function getPracticeDocuments(): Collection
    {
        return $this->practiceDocuments;
    }

    public function addPracticeDocument(PracticeDocument $practiceDocument): static
    {
        if (!$this->practiceDocuments->contains($practiceDocument)) {
            $this->practiceDocuments->add($practiceDocument);
            $practiceDocument->setPractice($this);
        }

        return $this;
    }

    /** Righe richieste per questa pratica (12.3.2.2: le nascoste non contano). */
    public function getVisibleDocuments(): Collection
    {
        return $this->practiceDocuments->filter(fn (PracticeDocument $pd) => $pd->isVisible());
    }

    /** Numero di righe documentali con almeno un allegato. */
    public function countUploaded(): int
    {
        return $this->getVisibleDocuments()->filter(fn (PracticeDocument $pd) => $pd->hasFiles())->count();
    }

    /** Numero di righe documentali richieste. */
    public function countRequested(): int
    {
        return $this->getVisibleDocuments()->count();
    }

    /**
     * 17.1.1 Righe richieste non ancora verificate: sono quelle che bloccano il
     * passaggio a "completata". Le righe nascoste (12.3.2.2) non contano, e
     * "non necessario" vale come verificata: quel documento non serve.
     *
     * @return Collection<int, PracticeDocument>
     */
    public function getDocumentsPendingVerification(): Collection
    {
        return $this->getVisibleDocuments()->filter(fn (PracticeDocument $pd) => !in_array(
            $pd->getStatus(),
            [PracticeDocument::STATUS_VERIFIED, PracticeDocument::STATUS_NOT_REQUIRED],
            true
        ));
    }

    /** 17.1.1 Il notaio segna "completata" solo a documenti richiesti tutti verificati. */
    public function getCanComplete(): bool
    {
        return $this->getDocumentsPendingVerification()->count() === 0;
    }

    /** 12.1.8 Spazio occupato dagli allegati della pratica, in MB. */
    public function getSizeMb(): float
    {
        $bytes = 0;
        foreach ($this->practiceDocuments as $practiceDocument) {
            $bytes += $practiceDocument->getSizeBytes();
        }

        return round($bytes / 1048576, 2);
    }

    /** @return Collection<int, PracticeAlert> */
    public function getAlerts(): Collection
    {
        return $this->alerts;
    }

    /**
     * Avvisi che vede l'agenzia: i suoi e quelli del notaio "visibile agenzia" (per data).
     *
     * @return Collection<int, PracticeAlert>
     */
    public function getAgencyAlerts(): Collection
    {
        return $this->alerts->filter(static fn (PracticeAlert $a) => $a->isShownToAgency());
    }

    /**
     * Avvisi che vede il notaio: i suoi e quelli dell'agenzia "mostra al notaio" (per data).
     *
     * @return Collection<int, PracticeAlert>
     */
    public function getNotaryAlerts(): Collection
    {
        return $this->alerts->filter(static fn (PracticeAlert $a) => $a->isShownToNotary());
    }

    public function addAlert(PracticeAlert $alert): static
    {
        if (!$this->alerts->contains($alert)) {
            $this->alerts->add($alert);
            $alert->setPractice($this);
        }

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /**
     * 12.3.5.2 L'archiviazione è possibile solo dopo il via libera del notaio, che è il
     * "completata": una pratica completata è archiviabile.
     */
    public function getCanArchive(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * 12.3.2 I documenti si toccano solo a pratica aperta: da "completata" in poi
     * (quindi anche "archiviata") sono in sola lettura, perché il fascicolo è già
     * passato al notaio.
     */
    public function areDocumentsEditable(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * 12.3.3 Chi può aprire la pratica: gli amministratori dell'agenzia sempre,
     * gli altri solo se inseriti fra lo staff della pratica.
     */
    public function isAccessibleBy(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        foreach ($this->staff as $member) {
            if ($member->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
    }

    public function __toString(): string
    {
        return (string) $this->number;
    }
}
