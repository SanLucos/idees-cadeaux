<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\ContributionPledgeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One friend's declared share of a contribution (spec §5.10). Its
 * `amount` is visible to its author and the contribution's initiator
 * only (CLAUDE.md règle 3) — App\Serializer\InteractionNormalizer.
 */
#[ORM\Entity(repositoryClass: ContributionPledgeRepository::class)]
#[ORM\Table(name: 'contribution_pledge')]
#[ORM\UniqueConstraint(name: 'uniq_pledge_active_user', columns: ['contribution_id', 'user_id'], options: ['where' => '(deleted_at IS NULL)'])]
class ContributionPledge implements TimestampableInterface, SoftDeletableInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    #[ORM\ManyToOne(targetEntity: Contribution::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Contribution $contribution;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    public function __construct(Contribution $contribution, User $user, string $amount, ?Uuid $id = null)
    {
        $this->initializeId($id);
        $this->initializeTimestamps();
        $this->contribution = $contribution;
        $this->user = $user;
        $this->amount = $amount;
    }

    public function getContribution(): Contribution
    {
        return $this->contribution;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): void
    {
        $this->amount = $amount;
    }
}
