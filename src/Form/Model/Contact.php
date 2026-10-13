<?php

namespace App\Form\Model;

/** 14.1 Modulo "Richiedi informazioni" della homepage: non si salva, diventa un'e-mail interna. */
class Contact
{
    private string $name = '';
    private string $surname = '';
    private string $email = '';
    private ?string $message = null;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = (string) $name;

        return $this;
    }

    public function getSurname(): string
    {
        return $this->surname;
    }

    public function setSurname(?string $surname): static
    {
        $this->surname = (string) $surname;

        return $this;
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

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;

        return $this;
    }
}
