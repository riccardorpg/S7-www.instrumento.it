<?php

namespace App\Form\Model;

/** 12.3.2.7 Notifica di aggiornamento file: destinatario (fra quelli della pratica) e messaggio. */
class PracticeNotify
{
    private ?string $recipient = null;
    private string $message;

    public function __construct(string $message = '')
    {
        $this->message = $message;
    }

    public function getRecipient(): ?string
    {
        return $this->recipient;
    }

    public function setRecipient(?string $recipient): static
    {
        $this->recipient = $recipient;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }
}
