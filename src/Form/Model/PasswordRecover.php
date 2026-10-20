<?php

namespace App\Form\Model;

/**
 * 3.3 Recupero password: l'e-mail dell'utente (unica su tutta la piattaforma).
 */
class PasswordRecover
{
    private string $email = '';

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = (string) $email;

        return $this;
    }

}
