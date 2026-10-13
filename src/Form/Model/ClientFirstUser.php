<?php

namespace App\Form\Model;

/**
 * Primo utente dell'agenzia, nel nuovo cliente creato da una richiesta demo: nome e cognome.
 * L'e-mail è quella della richiesta e il database dell'agenzia non esiste ancora, quindi il form
 * non può lavorare sull'utente dello slave: il controller lo crea dopo aver creato il database.
 */
class ClientFirstUser
{
    private string $name = '';
    private string $surname = '';

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
}
