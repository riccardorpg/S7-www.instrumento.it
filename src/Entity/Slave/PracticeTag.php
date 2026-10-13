<?php

namespace App\Entity\Slave;

use App\Entity\Trait\ColorValueTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampsTrait;
use App\Repository\Slave\PracticeTagRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * 13.3 Tag pratica: etichetta colorata configurata dall'agenzia,
 * nel DB dell'agenzia (slave).
 */
#[ORM\Entity(repositoryClass: PracticeTagRepository::class)]
#[ORM\Table(name: 'eb_s_practice_tag')]
#[ORM\HasLifecycleCallbacks]
class PracticeTag
{
    use IdTrait;
    use ColorValueTrait;
    use TimestampsTrait;

    public function __toString(): string
    {
        return $this->getValue();
    }
}
