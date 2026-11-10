<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\FriendSuggestionStatus;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\FriendSuggestionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * « Présenter à des amis » (spec §5.3, §11 décision 65): `suggestedBy`
 * suggests to their friend `recipient` (A) to befriend their friend
 * `suggested` (B). B learns nothing until A sends a request from it.
 * Gone for everyone 30 days after its creation (`expiresAt`), unless it
 * succeeded; a cancellation by its author is a soft delete.
 */
#[ORM\Entity(repositoryClass: FriendSuggestionRepository::class)]
#[ORM\Table(name: 'friend_suggestion')]
#[ORM\Index(columns: ['recipient_id', 'suggested_id'])]
class FriendSuggestion implements TimestampableInterface, SoftDeletableInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    public const string TTL = '30 days';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $suggestedBy;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $suggested;

    #[ORM\Column(enumType: FriendSuggestionStatus::class)]
    private FriendSuggestionStatus $status = FriendSuggestionStatus::Pending;

    /** The request A sent B from this suggestion. */
    #[ORM\ManyToOne(targetEntity: Friendship::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Friendship $friendship = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    public function __construct(User $suggestedBy, User $recipient, User $suggested)
    {
        $this->initializeId();
        $this->initializeTimestamps();
        $this->suggestedBy = $suggestedBy;
        $this->recipient = $recipient;
        $this->suggested = $suggested;
        $this->expiresAt = $this->getCreatedAt()->modify('+'.self::TTL);
    }

    public function getSuggestedBy(): User
    {
        return $this->suggestedBy;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getSuggested(): User
    {
        return $this->suggested;
    }

    public function getStatus(): FriendSuggestionStatus
    {
        return $this->status;
    }

    public function getFriendship(): ?Friendship
    {
        return $this->friendship;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable();
    }

    public function markSent(Friendship $friendship): void
    {
        $this->status = FriendSuggestionStatus::Sent;
        $this->friendship = $friendship;
    }

    public function dismiss(): void
    {
        $this->status = FriendSuggestionStatus::Dismissed;
    }

    public function markAccepted(): void
    {
        $this->status = FriendSuggestionStatus::Accepted;
    }

    public function cancel(): void
    {
        $this->markDeleted();
    }
}
