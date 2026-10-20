<?php

namespace App\Entity;

use App\Entity\Trait\ColorValueTrait;
use App\Entity\Trait\CompanyOwnedTrait;
use App\Entity\Trait\IdTrait;
use App\Entity\Trait\TimestampsTrait;
use App\Repository\PracticeTagRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * 13.3 Tag pratica: etichetta colorata configurata dall'agenzia,
 * nel DB dell'agenzia (slave).
 */
#[ORM\Entity(repositoryClass: PracticeTagRepository::class)]
#[ORM\Table(name: 'eb_practice_tag')]
#[ORM\HasLifecycleCallbacks]
class PracticeTag implements CompanyOwnedInterface
{
    use CompanyOwnedTrait;
    use IdTrait;
    use ColorValueTrait;
    use TimestampsTrait;

    public function __toString(): string
    {
        return $this->getValue();
    }
}
