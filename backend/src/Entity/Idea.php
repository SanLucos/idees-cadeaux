<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\IdeaArchiveKind;
use App\Entity\Enum\IdeaStatus;
use App\Entity\Enum\IdeaVisibility;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\IdeaRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A gift idea for `owner` (spec §5.4, §6). A *suggestion* is an idea
 * whose author isn't its owner: invisible to that owner, always
 * (CLAUDE.md règle 1). Who may see or change an idea is decided in one
 * place, App\Security\IdeaAccess — never inline in controllers.
 */
#[ORM\Entity(repositoryClass: IdeaRepository::class)]
#[ORM\Table(name: 'idea')]
#[ORM\Index(name: 'idx_idea_owner_status', columns: ['owner_id', 'status'])]
#[ORM\Index(name: 'idx_idea_author_visibility', columns: ['author_id', 'visibility'])]
class Idea implements TimestampableInterface, SoftDeletableInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    public const int TITLE_MAX_LENGTH = 120;
    public const int NOTE_MAX_LENGTH = 2000;
    public const int URL_MAX_LENGTH = 2048;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\Column(length: self::TITLE_MAX_LENGTH)]
    private string $title;

    #[ORM\Column(length: self::URL_MAX_LENGTH, nullable: true)]
    private ?string $url = null;

    /** Decimal string ("149.90"): never a float for money. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $priceAmount = null;

    #[ORM\Column(length: 3)]
    private string $priceCurrency = 'EUR';

    #[ORM\Column(nullable: true)]
    private ?string $imagePath = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne(targetEntity: Occasion::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Occasion $occasion = null;

    #[ORM\Column(enumType: IdeaVisibility::class)]
    private IdeaVisibility $visibility;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(enumType: IdeaStatus::class)]
    private IdeaStatus $status = IdeaStatus::Active;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $archivedBy = null;

    #[ORM\Column(nullable: true, enumType: IdeaArchiveKind::class)]
    private ?IdeaArchiveKind $archiveKind = null;

    public function __construct(User $owner, User $author, string $title, IdeaVisibility $visibility = IdeaVisibility::Published, ?Uuid $id = null)
    {
        $this->initializeId($id);
        $this->initializeTimestamps();
        $this->owner = $owner;
        $this->author = $author;
        $this->title = $title;
        $this->visibility = IdeaVisibility::Private;
        if (IdeaVisibility::Published === $visibility) {
            $this->publish();
        }
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function isSuggestion(): bool
    {
        return $this->author !== $this->owner;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): void
    {
        $this->url = $url;
    }

    public function getPriceAmount(): ?string
    {
        return $this->priceAmount;
    }

    public function getPriceCurrency(): string
    {
        return $this->priceCurrency;
    }

    public function setPrice(?string $amount, string $currency): void
    {
        $this->priceAmount = $amount;
        $this->priceCurrency = $currency;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(?string $imagePath): void
    {
        $this->imagePath = $imagePath;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
    }

    public function getOccasion(): ?Occasion
    {
        return $this->occasion;
    }

    public function setOccasion(?Occasion $occasion): void
    {
        $this->occasion = $occasion;
    }

    public function getVisibility(): IdeaVisibility
    {
        return $this->visibility;
    }

    public function isPublished(): bool
    {
        return IdeaVisibility::Published === $this->visibility;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function publish(): void
    {
        if ($this->isPublished()) {
            return;
        }
        $this->visibility = IdeaVisibility::Published;
        $this->publishedAt = new \DateTimeImmutable();
    }

    /**
     * Interactions (reservation, comments, reactions, contribution) are
     * purged by the caller from lot 4 — spec §5.4 "repasser en privé".
     */
    public function unpublish(): void
    {
        $this->visibility = IdeaVisibility::Private;
        $this->publishedAt = null;
    }

    public function getStatus(): IdeaStatus
    {
        return $this->status;
    }

    public function isArchived(): bool
    {
        return IdeaStatus::Archived === $this->status;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function getArchivedBy(): ?User
    {
        return $this->archivedBy;
    }

    public function getArchiveKind(): ?IdeaArchiveKind
    {
        return $this->archiveKind;
    }

    public function archive(User $by, IdeaArchiveKind $kind): void
    {
        $this->status = IdeaStatus::Archived;
        $this->archivedAt = new \DateTimeImmutable();
        $this->archivedBy = $by;
        $this->archiveKind = $kind;
    }

    public function unarchive(): void
    {
        $this->status = IdeaStatus::Active;
        $this->archivedAt = null;
        $this->archivedBy = null;
        $this->archiveKind = null;
    }
}
