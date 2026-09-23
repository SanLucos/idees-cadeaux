<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\NotificationType;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One in-app notification (spec §5.11), and the unit email/push
 * deliveries hang off. `user` is always an adult who can read it; when
 * it concerns a child profile, `subject` names that child and `user` is
 * its manager (« Pour Jules »).
 *
 * Payload: display data only (names, idea title and ids for the deep
 * link) — never an amount (CLAUDE.md règle 3), never anything the
 * recipient couldn't see in the app.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
#[ORM\Index(name: 'idx_notification_user_created', columns: ['user_id', 'created_at'])]
#[ORM\UniqueConstraint(name: 'uniq_notification_dedupe', columns: ['user_id', 'dedupe_key'])]
class Notification implements TimestampableInterface, SoftDeletableInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $subject;

    #[ORM\Column(enumType: NotificationType::class)]
    private NotificationType $type;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    /** Same event never notified twice to the same person (e.g. republishing). */
    #[ORM\Column(length: 190, nullable: true)]
    private ?string $dedupeKey;

    /**
     * False when the recipient turned in-app off for this type but still
     * gets it by email or push: the row then only carries the delivery.
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $inApp;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(User $user, NotificationType $type, array $payload, ?User $subject = null, ?string $dedupeKey = null, bool $inApp = true)
    {
        $this->inApp = $inApp;
        $this->initializeId();
        $this->initializeTimestamps();
        $this->user = $user;
        $this->type = $type;
        $this->payload = $payload;
        $this->subject = $subject;
        $this->dedupeKey = $dedupeKey;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSubject(): ?User
    {
        return $this->subject;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function markRead(): void
    {
        $this->readAt ??= new \DateTimeImmutable();
    }

    public function isInApp(): bool
    {
        return $this->inApp;
    }

    public function getDedupeKey(): ?string
    {
        return $this->dedupeKey;
    }
}
