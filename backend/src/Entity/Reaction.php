<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ReactionType;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\ReactionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** "J'aime" (spec §5.9): one per (idea, user, type), never visible to the owner. */
#[ORM\Entity(repositoryClass: ReactionRepository::class)]
#[ORM\Table(name: 'reaction')]
#[ORM\UniqueConstraint(name: 'uniq_reaction_active', columns: ['idea_id', 'user_id', 'type'], options: ['where' => '(deleted_at IS NULL)'])]
class Reaction implements TimestampableInterface, SoftDeletableInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    #[ORM\ManyToOne(targetEntity: Idea::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Idea $idea;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(enumType: ReactionType::class)]
    private ReactionType $type;

    public function __construct(Idea $idea, User $user, ReactionType $type = ReactionType::Like, ?Uuid $id = null)
    {
        $this->initializeId($id);
        $this->initializeTimestamps();
        $this->idea = $idea;
        $this->user = $user;
        $this->type = $type;
    }

    public function getIdea(): Idea
    {
        return $this->idea;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getType(): ReactionType
    {
        return $this->type;
    }
}
