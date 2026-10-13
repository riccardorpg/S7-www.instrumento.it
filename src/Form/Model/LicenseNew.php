<?php

namespace App\Form\Model;

use App\Entity\Master\LicenseType;

/**
 * Crea licenza (elenco licenze dell'amministratore): tipo e quantità. Non è una License:
 * il controller ne crea tante quante la quantità, tutte libere e con scadenza a un anno.
 */
class LicenseNew
{
    private ?LicenseType $type = null;
    private ?int $quantity = 1;

    public function getType(): ?LicenseType
    {
        return $this->type;
    }

    public function setType(?LicenseType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(?int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }
}
