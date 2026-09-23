<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ContributionStatus;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\ContributionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Cotisation à plusieurs (spec §5.10): declarative only, no payment,
 * hidden from the owner. One open contribution per idea (partial
 * unique index); closed ones stay as history.
 */
#[ORM\Entity(repositoryClass: ContributionRepository::class)]
#[ORM\Table(name: 'contribution')]
#[ORM\UniqueConstraint(name: 'uniq_contribution_open_idea', columns: ['idea_id'], options: ['where' => "(((status)::text = 'open'::text) AND (deleted_at IS NULL))"])] // Postgres' normalised form, so schema diffs stay clean
class Contribution implements TimestampableInterface, SoftDeletableInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    #[ORM\ManyToOne(targetEntity: Idea::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Idea $idea;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $initiator;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $targetAmount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(enumType: ContributionStatus::class)]
    private ContributionStatus $status = ContributionStatus::Open;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct(Idea $idea, User $initiator, ?string $targetAmount, string $currency, ?Uuid $id = null)
    {
        $this->initializeId($id);
        $this->initializeTimestamps();
        $this->idea = $idea;
        $this->initiator = $initiator;
        $this->targetAmount = $targetAmount;
        $this->currency = $currency;
    }

    public function getIdea(): Idea
    {
        return $this->idea;
    }

    public function getInitiator(): User
    {
        return $this->initiator;
    }

    public function getTargetAmount(): ?string
    {
        return $this->targetAmount;
    }

    public function setTargetAmount(?string $targetAmount): void
    {
        $this->targetAmount = $targetAmount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): ContributionStatus
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return ContributionStatus::Open === $this->status;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function close(): void
    {
        if (!$this->isOpen()) {
            return;
        }
        $this->status = ContributionStatus::Closed;
        $this->closedAt = new \DateTimeImmutable();
    }
}
