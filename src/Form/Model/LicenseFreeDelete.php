<?php

namespace App\Form\Model;

use App\Entity\LicenseType;

/**
 * Elimina licenze libere (scheda "Licenze libere" dell'amministratore): tipo e quantità.
 * Il controller controlla che ce ne siano abbastanza libere di quel tipo.
 */
class LicenseFreeDelete
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
