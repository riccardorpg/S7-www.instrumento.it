<?php

namespace App\Entity\Slave;

use App\Entity\Trait\IdTrait;
use App\Repository\Slave\PermissionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * 10.1.5 Voce del catalogo permessi: una riga per sezione della piattaforma.
 * Il catalogo è uguale per tutte le agenzie e viene allineato da `app:sync-permissions`;
 * a variare per utente è solo il livello, in {@see UserPermission}.
 */
#[ORM\Entity(repositoryClass: PermissionRepository::class)]
#[ORM\Table(name: 'eb_s_permission')]
class Permission
{
    use IdTrait;

    /** Identificatore stabile usato nel codice: is_granted('view'|'edit', 'practices'). */
    #[ORM\Column(name: 'slug', type: 'string', length: 64, unique: true)]
    private string $slug = '';

    /** Etichetta mostrata nella matrice permessi. */
    #[ORM\Column(name: 'value', type: 'string', length: 190)]
    private string $value = '';

    /** Ordine di comparsa nella matrice. */
    #[ORM\Column(name: 'priority', type: 'integer', options: ['default' => 0])]
    private int $priority = 0;

    /** @var Collection<int, UserPermission> */
    #[ORM\OneToMany(mappedBy: 'permission', targetEntity: UserPermission::class, cascade: ['remove'], orphanRemoval: true)]
    private Collection $userPermissions;

    public function __construct()
    {
        $this->userPermissions = new ArrayCollection();
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    /** @return Collection<int, UserPermission> */
    public function getUserPermissions(): Collection
    {
        return $this->userPermissions;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
