<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\VerificationCodePurpose;
use App\Entity\Trait\IdentifiableTrait;
use App\Repository\VerificationCodeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Internal auth artifact backing "vérification de l'email (lien ou
 * code)" and "mot de passe oublié" (spec §5.1) — not part of the
 * synced domain model (spec §6), so it's disposable and hard-deleted
 * rather than following the Identifiable/Timestampable/SoftDeletable
 * conventions, same reasoning as App\Entity\RefreshToken.
 */
#[ORM\Entity(repositoryClass: VerificationCodeRepository::class)]
#[ORM\Table(name: 'verification_code')]
class VerificationCode
{
    use IdentifiableTrait;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(enumType: VerificationCodePurpose::class)]
    private VerificationCodePurpose $purpose;

    #[ORM\Column(length: 64)]
    private string $codeHash;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private int $attempts = 0;

    public function __construct(User $user, VerificationCodePurpose $purpose, string $codeHash, \DateTimeImmutable $expiresAt)
    {
        $this->initializeId();
        $this->user = $user;
        $this->purpose = $purpose;
        $this->codeHash = $codeHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPurpose(): VerificationCodePurpose
    {
        return $this->purpose;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function registerAttempt(): void
    {
        ++$this->attempts;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }
}
