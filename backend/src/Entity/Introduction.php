<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\IntroductionAnswer;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\IntroductionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * « L'équipe vous propose de devenir ami avec X » (spec §5.17, §11
 * décision 71): the administrator introduces A and B to each other;
 * they become friends once both accepted (`friendship`). Gone 30 days
 * after its creation; cancelled by the administrator = soft delete.
 * `createdBy` is never shown to A or B: it's « l'équipe ».
 */
#[ORM\Entity(repositoryClass: IntroductionRepository::class)]
#[ORM\Table(name: 'introduction')]
#[ORM\Index(columns: ['user_a_id', 'user_b_id'])]
class Introduction implements TimestampableInterface, SoftDeletableInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    public const string TTL = '30 days';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $userA;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $userB;

    #[ORM\Column(nullable: true, enumType: IntroductionAnswer::class)]
    private ?IntroductionAnswer $answerA = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $answeredAtA = null;

    #[ORM\Column(nullable: true, enumType: IntroductionAnswer::class)]
    private ?IntroductionAnswer $answerB = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $answeredAtB = null;

    /** Created once both accepted. */
    #[ORM\ManyToOne(targetEntity: Friendship::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Friendship $friendship = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    public function __construct(User $userA, User $userB, ?User $createdBy)
    {
        $this->initializeId();
        $this->initializeTimestamps();
        $this->userA = $userA;
        $this->userB = $userB;
        $this->createdBy = $createdBy;
        $this->expiresAt = $this->getCreatedAt()->modify('+'.self::TTL);
    }

    public function getUserA(): User
    {
        return $this->userA;
    }

    public function getUserB(): User
    {
        return $this->userB;
    }

    public function involves(User $user): bool
    {
        return $user === $this->userA || $user === $this->userB;
    }

    public function otherThan(User $user): User
    {
        return $user === $this->userA ? $this->userB : $this->userA;
    }

    public function answerOf(User $user): ?IntroductionAnswer
    {
        return $user === $this->userA ? $this->answerA : ($user === $this->userB ? $this->answerB : null);
    }

    public function answeredAtOf(User $user): ?\DateTimeImmutable
    {
        return $user === $this->userA ? $this->answeredAtA : ($user === $this->userB ? $this->answeredAtB : null);
    }

    public function answer(User $user, IntroductionAnswer $answer): void
    {
        $now = new \DateTimeImmutable();
        if ($user === $this->userA) {
            $this->answerA = $answer;
            $this->answeredAtA = $now;
        } elseif ($user === $this->userB) {
            $this->answerB = $answer;
            $this->answeredAtB = $now;
        }
    }

    public function bothAccepted(): bool
    {
        return IntroductionAnswer::Accepted === $this->answerA && IntroductionAnswer::Accepted === $this->answerB;
    }

    public function wasIgnored(): bool
    {
        return IntroductionAnswer::Ignored === $this->answerA || IntroductionAnswer::Ignored === $this->answerB;
    }

    public function getFriendship(): ?Friendship
    {
        return $this->friendship;
    }

    public function markFriends(Friendship $friendship): void
    {
        $this->friendship = $friendship;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= new \DateTimeImmutable();
    }

    /** Still waiting for an answer from at least one of them, as far as anyone can tell. */
    public function isOpen(): bool
    {
        return !$this->isDeleted() && !$this->isExpired() && null === $this->friendship && !$this->wasIgnored();
    }

    /** Closed without friendship: they became friends otherwise, or the administrator cancelled it. */
    public function close(): void
    {
        $this->markDeleted();
    }
}
