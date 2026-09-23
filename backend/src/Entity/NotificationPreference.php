<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\NotificationChannel;
use App\Entity\Enum\NotificationType;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\NotificationPreferenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Spec §5.11 / §6 "préférences réglables par type et par canal":
 * - `type` set: an override of that type's default for that channel;
 * - `type` null: the channel's consent (push, email) — `enabled` plus
 *   the timestamp it was given (`consentedAt`, spec §5.11 "consentement
 *   horodaté par canal"). No row = no consent.
 */
#[ORM\Entity(repositoryClass: NotificationPreferenceRepository::class)]
#[ORM\Table(name: 'notification_preference')]
#[ORM\UniqueConstraint(name: 'uniq_notification_preference', columns: ['user_id', 'channel', 'type'])]
// One consent row per channel: NULL types aren't equal in a plain unique index.
#[ORM\UniqueConstraint(name: 'uniq_notification_consent', columns: ['user_id', 'channel'], options: ['where' => '(type IS NULL)'])]
class NotificationPreference implements TimestampableInterface, SoftDeletableInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(enumType: NotificationChannel::class)]
    private NotificationChannel $channel;

    #[ORM\Column(nullable: true, enumType: NotificationType::class)]
    private ?NotificationType $type;

    #[ORM\Column]
    private bool $enabled;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consentedAt = null;

    public function __construct(User $user, NotificationChannel $channel, ?NotificationType $type, bool $enabled)
    {
        $this->initializeId();
        $this->initializeTimestamps();
        $this->user = $user;
        $this->channel = $channel;
        $this->type = $type;
        $this->setEnabled($enabled);
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    public function getType(): ?NotificationType
    {
        return $this->type;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        // A consent row keeps when it was (last) given.
        if (null === $this->type && $enabled) {
            $this->consentedAt = new \DateTimeImmutable();
        }
    }

    public function getConsentedAt(): ?\DateTimeImmutable
    {
        return $this->consentedAt;
    }
}
