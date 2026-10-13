<?php

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Etichetta colorata: campi comuni a contrassegni (13.2) e tag (13.3) delle pratiche.
 * Il colore è un esadecimale #RRGGBB, quello scelto nel form di configurazione.
 */
trait ColorValueTrait
{
    /** Valore: testo dell'etichetta. */
    #[ORM\Column(name: 'value', type: 'string', length: 190)]
    protected string $value = '';

    /** Colore di sfondo dell'etichetta. */
    #[ORM\Column(name: 'color', type: 'string', length: 7, options: ['default' => '#1D4ED8'])]
    protected string $color = '#1D4ED8';

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = $color;

        return $this;
    }

    /**
     * Colore del testo leggibile sopra $color: nero sui fondi chiari, bianco sugli scuri.
     * Luminanza percepita secondo i coefficienti ITU-R BT.601.
     */
    public function getContrastColor(): string
    {
        $hex = ltrim($this->color, '#');
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return '#ffffff';
        }

        $luminance = (
            0.299 * hexdec(substr($hex, 0, 2))
            + 0.587 * hexdec(substr($hex, 2, 2))
            + 0.114 * hexdec(substr($hex, 4, 2))
        ) / 255;

        return $luminance > 0.6 ? '#10201c' : '#ffffff';
    }
}
