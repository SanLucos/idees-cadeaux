<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\CommentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Flat comment thread on an idea (spec §5.8), never visible to its owner. */
#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comment')]
#[ORM\Index(name: 'idx_comment_idea', columns: ['idea_id', 'created_at'])]
class Comment implements TimestampableInterface, SoftDeletableInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    public const int BODY_MAX_LENGTH = 1000;

    #[ORM\ManyToOne(targetEntity: Idea::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Idea $idea;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $editedAt = null;

    public function __construct(Idea $idea, User $author, string $body, ?Uuid $id = null)
    {
        $this->initializeId($id);
        $this->initializeTimestamps();
        $this->idea = $idea;
        $this->author = $author;
        $this->body = $body;
    }

    public function getIdea(): Idea
    {
        return $this->idea;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function edit(string $body): void
    {
        $this->body = $body;
        $this->editedAt = new \DateTimeImmutable();
    }

    public function getEditedAt(): ?\DateTimeImmutable
    {
        return $this->editedAt;
    }
}
