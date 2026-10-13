<?php

namespace App\Form\Model;

/**
 * 3.3 Recupero password: e-mail e, dalla pagina di accesso dell'agenzia, il suo codice
 * (così l'utente si cerca nel database di quell'agenzia e non sul master).
 */
class PasswordRecover
{
    private string $email = '';
    private ?string $code = null;

    public function __construct(?string $code = null)
    {
        $this->code = $code;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = (string) $email;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code;

        return $this;
    }
}
