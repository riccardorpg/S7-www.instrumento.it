<?php

namespace App\Entity;

use App\Entity\Trait\IdTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * 10.1.5 Livello di un membro dello staff su una sezione della piattaforma.
 * Riga presente solo se il livello è "lettura" o "lettura/scrittura": l'assenza
 * della riga vale "nessuno" (10.1.5.3).
 */
#[ORM\Entity]
#[ORM\Table(name: 'eb_user_permission')]
#[ORM\UniqueConstraint(name: 'uniq_user_permission', columns: ['user_id', 'permission_id'])]
class UserPermission
{
    use IdTrait;

    // 10.1.5.1 / 10.1.5.2 — l'assenza di riga è "nessuno".
    public const READ = 'r';
    public const READ_WRITE = 'rw';

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'userPermissions')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Permission::class, inversedBy: 'userPermissions')]
    #[ORM\JoinColumn(name: 'permission_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Permission $permission = null;

    #[ORM\Column(name: 'value', type: 'string', length: 2)]
    private string $value = self::READ;

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getPermission(): ?Permission
    {
        return $this->permission;
    }

    public function setPermission(?Permission $permission): static
    {
        $this->permission = $permission;

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
}
