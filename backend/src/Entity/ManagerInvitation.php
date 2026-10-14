<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Repository\ManagerInvitationRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * « Ajouter un gestionnaire » (spec §5.15, §11 décision 60): a manager
 * invites one of their adult friends to manage a child with them.
 * Valid 7 days, one at a time per child; removed once answered or
 * cancelled. A refusal and an expiry look the same to the inviter: the
 * invitation is simply gone.
 */
#[ORM\Entity(repositoryClass: ManagerInvitationRepository::class)]
#[ORM\Table(name: 'manager_invitation')]
class ManagerInvitation
{
    use IdentifiableTrait;

    public const string TTL = '7 days';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $child;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $invitee;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $invitedBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    public function __construct(User $child, User $invitee, User $invitedBy)
    {
        $this->initializeId();
        $this->child = $child;
        $this->invitee = $invitee;
        $this->invitedBy = $invitedBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify('+'.self::TTL);
    }

    public function getChild(): User
    {
        return $this->child;
    }

    public function getInvitee(): User
    {
        return $this->invitee;
    }

    public function getInvitedBy(): User
    {
        return $this->invitedBy;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isUsable(): bool
    {
        return $this->expiresAt > new \DateTimeImmutable();
    }
}
