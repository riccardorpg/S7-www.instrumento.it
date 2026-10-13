<?php

namespace App\Form\Model;

/** 4. Creazione della password dal link monouso: password e conferma. */
class PasswordCreate
{
    private ?string $password = null;
    private ?string $passwordConfirm = null;

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getPasswordConfirm(): ?string
    {
        return $this->passwordConfirm;
    }

    public function setPasswordConfirm(?string $passwordConfirm): static
    {
        $this->passwordConfirm = $passwordConfirm;

        return $this;
    }
}
