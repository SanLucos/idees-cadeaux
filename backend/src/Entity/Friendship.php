<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\FriendshipOrigin;
use App\Entity\Enum\FriendshipStatus;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\FriendshipRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A friend request and, once accepted, the friendship itself (spec
 * §5.3). One row per relationship, reused across its lifecycle:
 * pending → accepted/declined, or soft-deleted for a requester's
 * cancellation or (once accepted) a removal — see FriendshipStatus.
 *
 * `declined` must never reach the requester (CLAUDE.md règle 4): that
 * masking happens at the serialization layer
 * (App\Serializer\FriendshipNormalizer), never here.
 */
#[ORM\Entity(repositoryClass: FriendshipRepository::class)]
#[ORM\Table(name: 'friendship')]
class Friendship implements TimestampableInterface, SoftDeletableInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $requester;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $addressee;

    #[ORM\Column(enumType: FriendshipStatus::class)]
    private FriendshipStatus $status;

    #[ORM\Column(enumType: FriendshipOrigin::class)]
    private FriendshipOrigin $origin;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $respondedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $removedBy = null;

    /**
     * Spec §5.15/§6: the manager who acted for a managed requester
     * ("au nom de [enfant]"), for display and audit.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $onBehalfOfManager = null;

    public function __construct(User $requester, User $addressee, FriendshipOrigin $origin = FriendshipOrigin::Request)
    {
        $this->initializeId();
        $this->initializeTimestamps();
        $this->requester = $requester;
        $this->addressee = $addressee;
        $this->status = FriendshipStatus::Pending;
        $this->origin = $origin;
        $this->expiresAt = $this->getCreatedAt()->modify('+30 days');
    }

    public function getOnBehalfOfManager(): ?User
    {
        return $this->onBehalfOfManager;
    }

    public function setOnBehalfOfManager(?User $manager): void
    {
        $this->onBehalfOfManager = $manager;
    }

    /** Spec §5.15: rattachement → friendship with the former manager, created accepted. */
    public static function createAccepted(User $a, User $b, FriendshipOrigin $origin): self
    {
        $friendship = new self($a, $b, $origin);
        $friendship->accept();

        return $friendship;
    }

    public function getRequester(): User
    {
        return $this->requester;
    }

    public function getAddressee(): User
    {
        return $this->addressee;
    }

    public function getStatus(): FriendshipStatus
    {
        return $this->status;
    }

    public function getOrigin(): FriendshipOrigin
    {
        return $this->origin;
    }

    public function getRespondedAt(): ?\DateTimeImmutable
    {
        return $this->respondedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRemovedBy(): ?User
    {
        return $this->removedBy;
    }

    public function isPending(): bool
    {
        return FriendshipStatus::Pending === $this->status;
    }

    public function isAccepted(): bool
    {
        return FriendshipStatus::Accepted === $this->status;
    }

    public function involves(User $user): bool
    {
        return $this->requester === $user || $this->addressee === $user;
    }

    public function otherParty(User $user): User
    {
        return $this->requester === $user ? $this->addressee : $this->requester;
    }

    public function accept(): void
    {
        $this->status = FriendshipStatus::Accepted;
        $this->respondedAt = new \DateTimeImmutable();
    }

    public function decline(): void
    {
        $this->status = FriendshipStatus::Declined;
        $this->respondedAt = new \DateTimeImmutable();
    }

    public function expire(): void
    {
        $this->status = FriendshipStatus::Expired;
    }

    public function cancel(): void
    {
        $this->markDeleted();
    }

    public function remove(User $by): void
    {
        $this->removedBy = $by;
        $this->markDeleted();
    }
}
