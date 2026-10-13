<?php

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Campi e comportamento comuni agli User dei due DB (Master e Slave).
 * La classe che lo usa deve implementare UserInterface e PasswordAuthenticatedUserInterface
 * e definire il valore di default della proprietà $role tramite il costruttore o la colonna.
 */
trait UserSecurityTrait
{
    #[ORM\Column(name: 'email', type: 'string', length: 191, unique: true)]
    protected ?string $email = null;

    #[ORM\Column(name: 'name', type: 'string', length: 191, nullable: true)]
    protected ?string $name = null;

    #[ORM\Column(name: 'surname', type: 'string', length: 191, nullable: true)]
    protected ?string $surname = null;

    #[ORM\Column(name: 'password', type: 'string', nullable: true)]
    protected ?string $password = null;

    /** Un solo ruolo per utente, salvato come stringa. */
    #[ORM\Column(name: 'role', type: 'string', length: 64)]
    protected string $role = 'ROLE_USER';

    #[ORM\Column(name: 'theme', type: 'string', length: 16, options: ['default' => 'light'])]
    protected string $theme = 'light';

    /** Codice usa-e-getta per attivazione account / recupero password. */
    #[ORM\Column(name: 'one_time_code', type: 'string', length: 191, nullable: true)]
    protected ?string $oneTimeCode = null;

    #[ORM\Column(name: 'expiration_one_time_code', type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $expirationOneTimeCode = null;

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSurname(): ?string
    {
        return $this->surname;
    }

    public function setSurname(?string $surname): static
    {
        $this->surname = $surname;

        return $this;
    }

    public function getFullName(): string
    {
        return trim(($this->name ?? '') . ' ' . ($this->surname ?? '')) ?: (string) $this->email;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = $role;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getRoles(): array
    {
        return [$this->role ?: 'ROLE_USER'];
    }

    public function getTheme(): string
    {
        return $this->theme;
    }

    public function setTheme(string $theme): static
    {
        $this->theme = $theme;

        return $this;
    }

    public function getOneTimeCode(): ?string
    {
        return $this->oneTimeCode;
    }

    public function setOneTimeCode(?string $oneTimeCode): static
    {
        $this->oneTimeCode = $oneTimeCode;

        return $this;
    }

    public function getExpirationOneTimeCode(): ?\DateTimeImmutable
    {
        return $this->expirationOneTimeCode;
    }

    public function setExpirationOneTimeCode(?\DateTimeImmutable $expiration): static
    {
        $this->expirationOneTimeCode = $expiration;

        return $this;
    }

    public function isOneTimeCodeValid(string $code): bool
    {
        return $this->oneTimeCode !== null
            && hash_equals($this->oneTimeCode, $code)
            && $this->expirationOneTimeCode !== null
            && $this->expirationOneTimeCode > new \DateTimeImmutable();
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function eraseCredentials(): void
    {
        // no-op: nessun dato sensibile temporaneo memorizzato in chiaro
    }
}
