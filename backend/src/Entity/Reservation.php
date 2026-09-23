<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\ReservationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * "Je l'offre" (spec §5.7). Hidden from the idea's owner (règle 1).
 * One active reservation per idea, enforced by a partial unique index
 * so two concurrent syncs can't both win (spec §5.7 "la première
 * synchronisation gagne").
 */
#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Table(name: 'reservation')]
#[ORM\UniqueConstraint(name: 'uniq_reservation_active_idea', columns: ['idea_id'], options: ['where' => '(deleted_at IS NULL)'])]
class Reservation implements TimestampableInterface, SoftDeletableInterface
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
