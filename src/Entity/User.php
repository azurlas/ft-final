<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] public ?int $id = null;
    #[ORM\Column(length: 64, unique: true)] public string $login = '';
    #[ORM\Column(length: 100)] public string $username = '';
    #[ORM\Column(name: 'password_hash', length: 255)] public string $passwordHash = '';
    #[ORM\Column(name: 'is_admin')] public bool $isAdmin = false;
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')] public \DateTimeImmutable $createdAt;
    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
    public function getUserIdentifier(): string
    {
        return $this->login;
    }
    public function getRoles(): array
    {
        return $this->isAdmin ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'];
    }
    public function getPassword(): string
    {
        return $this->passwordHash;
    }
    public function eraseCredentials(): void
    {
    }
}
