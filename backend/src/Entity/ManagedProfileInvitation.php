<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Repository\ManagedProfileInvitationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Rattacher un email" (spec §5.15): an invitation to take over a
 * managed profile, valid 7 days, redeemed with a 6-digit code (spec
 * §11 décision 26). Internal auth artifact like VerificationCode:
 * not synced, hard-deleted once used or replaced.
 */
#[ORM\Entity(repositoryClass: ManagedProfileInvitationRepository::class)]
#[ORM\Table(name: 'managed_profile_invitation')]
#[ORM\Index(name: 'idx_invitation_email', columns: ['email'])]
class ManagedProfileInvitation
{
    use IdentifiableTrait;

    public const string TTL = '7 days';
    public const int MAX_ATTEMPTS = 5;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $profile;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 64)]
    private string $codeHash;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private int $attempts = 0;

    public function __construct(User $profile, string $email, string $codeHash)
    {
        $this->initializeId();
        $this->profile = $profile;
        $this->email = $email;
        $this->codeHash = $codeHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify('+'.self::TTL);
    }

    public function getProfile(): User
    {
        return $this->profile;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isUsable(): bool
    {
        return $this->expiresAt > new \DateTimeImmutable() && $this->attempts < self::MAX_ATTEMPTS;
    }

    public function registerFailedAttempt(): void
    {
        ++$this->attempts;
    }
}
