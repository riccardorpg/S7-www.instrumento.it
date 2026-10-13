<?php

namespace App\Entity\Slave;

use App\Entity\Trait\ColorValueTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampsTrait;
use App\Repository\Slave\PracticeMarkRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * 13.2 Contrassegno pratica: etichetta colorata configurata dall'agenzia,
 * nel DB dell'agenzia (slave).
 */
#[ORM\Entity(repositoryClass: PracticeMarkRepository::class)]
#[ORM\Table(name: 'eb_s_practice_mark')]
#[ORM\HasLifecycleCallbacks]
class PracticeMark
{
    use IdTrait;
    use ColorValueTrait;
    use TimestampsTrait;

    public function __toString(): string
    {
        return $this->getValue();
    }
}
