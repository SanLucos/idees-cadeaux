<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Repository\EmailSignInCodeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sign-in without a password (spec §11 décision 68): a 6-digit code, also
 * carried by the link of the same email. Keyed by email rather than by
 * user, since the first sign-in creates the account. Internal auth
 * artifact, hard-deleted like App\Entity\VerificationCode.
 */
#[ORM\Entity(repositoryClass: EmailSignInCodeRepository::class)]
#[ORM\Table(name: 'email_sign_in_code')]
#[ORM\Index(columns: ['email'])]
class EmailSignInCode
{
    use IdentifiableTrait;

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

    public function __construct(string $email, string $codeHash, \DateTimeImmutable $expiresAt)
    {
        $this->initializeId();
        $this->email = $email;
        $this->codeHash = $codeHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getEmail(): string
    {
        return $this->email;
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
