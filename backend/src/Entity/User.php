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

    /**
     * Null until onboarding (spec §5.1) sets a pseudo, except for
     * managed profiles which always get one at creation (spec §5.15).
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $displayName = null;

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

    /**
     * Managed profiles only (spec §5.15, §5.13): when the manager ticked
     * "je suis titulaire de l'autorité parentale" at creation.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $parentalConsentAt = null;

    public const array DEFAULT_BIRTHDAY_REMINDER_DAYS = [14, 2];

    /**
     * Spec §5.11 / §11 décision 29: up to 3 delays (0–30 days) before a
     * friend's birthday; empty = reminders off. Global, not per friend.
     *
     * @var int[]
     */
    #[ORM\Column(type: 'json', options: ['default' => '[14,2]'])]
    private array $birthdayReminderDays = self::DEFAULT_BIRTHDAY_REMINDER_DAYS;

    public function __construct(
        UserType $type,
        ?string $displayName = null,
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

    /** Spec §5.15: `$manager` is this managed profile's one manager. */
    public function isManagedBy(self $manager): bool
    {
        return $this->isManaged() && $this->managedBy === $manager;
    }

    public static function createManaged(self $manager, string $displayName, ?Uuid $id = null): self
    {
        $child = new self(UserType::Managed, $displayName, $manager->getLocale(), $manager->getTimezone(), $id);
        $child->managedBy = $manager;
        $child->parentalConsentAt = new \DateTimeImmutable();

        return $child;
    }

    /**
     * @return int[]
     */
    public function getBirthdayReminderDays(): array
    {
        return $this->birthdayReminderDays;
    }

    /**
     * @param int[] $days
     */
    public function setBirthdayReminderDays(array $days): void
    {
        $this->birthdayReminderDays = array_values(array_unique($days));
        rsort($this->birthdayReminderDays);
    }

    /** Spec §5.13: during the 14-day grace period nothing is sent to or about the account. */
    public function isSuspended(): bool
    {
        return null !== $this->deletionScheduledAt || ($this->isManaged() && null !== $this->managedBy?->getDeletionScheduledAt());
    }

    public function getParentalConsentAt(): ?\DateTimeImmutable
    {
        return $this->parentalConsentAt;
    }

    /**
     * Spec §5.15 "rattacher un email": the profile becomes an autonomous
     * account, keeps everything, and its manager's access ends.
     */
    public function convertToAutonomous(string $email): void
    {
        $this->type = UserType::Regular;
        $this->managedBy = null;
        $this->email = $email;
        $this->emailVerifiedAt = new \DateTimeImmutable();
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

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
    }

    /**
     * A freshly registered account has no pseudo yet (spec §5.1
     * onboarding); the client must route it through onboarding before
     * the rest of the app.
     */
    public function isOnboarded(): bool
    {
        return null !== $this->displayName;
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
