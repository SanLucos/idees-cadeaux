<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\UserType;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A regular (autonomous) or managed (profil enfant, spec §5.15) account.
 * A managed profile has no email/password and is only ever acted upon
 * by its managedBy, via the X-Acting-As header (App\Security\ActingAsContext).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TimestampableInterface, SoftDeletableInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    #[ORM\Column(enumType: UserType::class)]
    private UserType $type;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'managed_by_id', nullable: true, onDelete: 'CASCADE')]
    private ?self $managedBy = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(nullable: true)]
    private ?string $passwordHash = null;

    #[ORM\Column(length: 30)]
    private string $displayName;

    #[ORM\Column(nullable: true)]
    private ?string $avatarPath = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $birthDay = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $birthMonth = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $birthYear = null;

    #[ORM\Column(length: 10)]
    private string $locale;

    #[ORM\Column(length: 64)]
    private string $timezone;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletionScheduledAt = null;

    public function __construct(
        UserType $type,
        string $displayName,
        string $locale = 'fr',
        string $timezone = 'Europe/Paris',
        ?Uuid $id = null,
    ) {
        $this->initializeId($id);
        $this->initializeTimestamps();
        $this->type = $type;
        $this->displayName = $displayName;
        $this->locale = $locale;
        $this->timezone = $timezone;
    }

    public function getType(): UserType
    {
        return $this->type;
    }

    public function getManagedBy(): ?self
    {
        return $this->managedBy;
    }

    public function setManagedBy(?self $managedBy): void
    {
        $this->managedBy = $managedBy;
    }

    public function isManaged(): bool
    {
        return UserType::Managed === $this->type;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getPasswordHash(): ?string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(?string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
    }

    public function getAvatarPath(): ?string
    {
        return $this->avatarPath;
    }

    public function setAvatarPath(?string $avatarPath): void
    {
        $this->avatarPath = $avatarPath;
    }

    public function getBirthDay(): ?int
    {
        return $this->birthDay;
    }

    public function getBirthMonth(): ?int
    {
        return $this->birthMonth;
    }

    public function getBirthYear(): ?int
    {
        return $this->birthYear;
    }

    public function setBirthDate(?int $day, ?int $month, ?int $year): void
    {
        $this->birthDay = $day;
        $this->birthMonth = $month;
        $this->birthYear = $year;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): void
    {
        $this->timezone = $timezone;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function setEmailVerifiedAt(?\DateTimeImmutable $emailVerifiedAt): void
    {
        $this->emailVerifiedAt = $emailVerifiedAt;
    }

    public function getDeletionScheduledAt(): ?\DateTimeImmutable
    {
        return $this->deletionScheduledAt;
    }

    public function setDeletionScheduledAt(?\DateTimeImmutable $deletionScheduledAt): void
    {
        $this->deletionScheduledAt = $deletionScheduledAt;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): ?string
    {
        return $this->passwordHash;
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->email ?? $this->getId()->toRfc4122();
    }
}
