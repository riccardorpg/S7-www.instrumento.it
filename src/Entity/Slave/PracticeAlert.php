<?php

namespace App\Entity\Slave;

use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampsTrait;
use App\Repository\Slave\PracticeAlertRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * 12.3.4 Avviso su una pratica: un promemoria con data e messaggio.
 */
#[ORM\Entity(repositoryClass: PracticeAlertRepository::class)]
#[ORM\Table(name: 'eb_s_practice_alert')]
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

    /** Avviso la cui data è arrivata (o passata): da evidenziare nell'elenco. */
    public function isDue(): bool
    {
        return $this->remindAt !== null && $this->remindAt <= new \DateTimeImmutable('today');
    }
}
