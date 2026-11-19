<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One of an idea's recipients (spec §11 décision 69): an idea shows on
 * the list of each of them, and every one of them is its owner for the
 * règle d'or. Only changed while the idea is private (its author's
 * alone), so nobody ever turns into — or stops being — the recipient of
 * an idea others have interacted with.
 */
#[ORM\Entity]
#[ORM\Table(name: 'idea_recipient')]
#[ORM\Index(name: 'idx_idea_recipient_user', columns: ['user_id'])]
#[ORM\UniqueConstraint(name: 'uniq_idea_recipient_active', columns: ['idea_id', 'user_id'], options: ['where' => '(deleted_at IS NULL)'])]
class IdeaRecipient implements TimestampableInterface, SoftDeletableInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    #[ORM\ManyToOne(targetEntity: Idea::class, inversedBy: 'recipients')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Idea $idea;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    public function __construct(Idea $idea, User $user, ?Uuid $id = null)
    {
        $this->initializeId($id);
        $this->initializeTimestamps();
        $this->idea = $idea;
        $this->user = $user;
    }

    public function getIdea(): Idea
    {
        return $this->idea;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
