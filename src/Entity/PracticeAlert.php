<?php

namespace App\Entity;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampsTrait;
use App\Repository\PracticeAlertRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * 12.3.4 Avviso su una pratica: un promemoria con data e messaggio.
 */
#[ORM\Entity(repositoryClass: PracticeAlertRepository::class)]
#[ORM\Table(name: 'eb_practice_alert')]
#[ORM\HasLifecycleCallbacks]
class PracticeAlert
{
    use IdTrait;
    use TimestampsTrait;

    #[ORM\ManyToOne(targetEntity: Practice::class, inversedBy: 'alerts')]
    #[ORM\JoinColumn(name: 'practice_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Practice $practice = null;

    /** 12.3.4.2.1 Ricorda in data. */
    #[ORM\Column(name: 'remind_at', type: 'date_immutable')]
    private ?\DateTimeImmutable $remindAt = null;

    /** 12.3.4.2.2 Messaggio. */
    #[ORM\Column(name: 'message', type: 'text')]
    private string $message = '';

    /** Mostra al notaio (avviso dell'agenzia): compare nella sua scheda pratica (tab "Avvisi") e sulla sua scrivania. */
    #[ORM\Column(name: 'visible_to_notary', type: 'boolean', options: ['default' => false])]
    private bool $visibleToNotary = false;

    /** Scritto dal notaio, dalla sua scheda pratica: lo modifica ed elimina solo lui. */
    #[ORM\Column(name: 'by_notary', type: 'boolean', options: ['default' => false])]
    private bool $byNotary = false;

    /** Visibile all'agenzia (avviso del notaio): compare, in sola lettura, nella scheda e sulla scrivania dell'agenzia. */
    #[ORM\Column(name: 'visible_to_agency', type: 'boolean', options: ['default' => false])]
    private bool $visibleToAgency = false;

    public function getPractice(): ?Practice
    {
        return $this->practice;
    }

    public function setPractice(?Practice $practice): static
    {
        $this->practice = $practice;

        return $this;
    }

    public function getRemindAt(): ?\DateTimeImmutable
    {
        return $this->remindAt;
    }

    public function setRemindAt(\DateTimeImmutable $remindAt): static
    {
        $this->remindAt = $remindAt;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function isVisibleToNotary(): bool
    {
        return $this->visibleToNotary;
    }

    public function setVisibleToNotary(bool $visibleToNotary): static
    {
        $this->visibleToNotary = $visibleToNotary;

        return $this;
    }

    public function isByNotary(): bool
    {
        return $this->byNotary;
    }

    public function setByNotary(bool $byNotary): static
    {
        $this->byNotary = $byNotary;

        return $this;
    }

    public function isVisibleToAgency(): bool
    {
        return $this->visibleToAgency;
    }

    public function setVisibleToAgency(bool $visibleToAgency): static
    {
        $this->visibleToAgency = $visibleToAgency;

        return $this;
    }

    /** Lo vede l'agenzia: i suoi avvisi, più quelli del notaio segnati "visibile agenzia". */
    public function isShownToAgency(): bool
    {
        return !$this->byNotary || $this->visibleToAgency;
    }

    /** Lo vede il notaio: i suoi avvisi, più quelli dell'agenzia segnati "mostra al notaio". */
    public function isShownToNotary(): bool
    {
        return $this->byNotary || $this->visibleToNotary;
    }

    /** Avviso la cui data è arrivata (o passata): da evidenziare nell'elenco. */
    public function isDue(): bool
    {
        return $this->remindAt !== null && $this->remindAt <= new \DateTimeImmutable('today');
    }
}
