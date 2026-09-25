<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\ShareLinkRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Partage du profil par lien" (spec §5.16): one active link per owner.
 * Regenerating or disabling soft-deletes the row, so an old token then
 * matches nothing — the same generic answer as a token that never
 * existed.
 *
 * The token is kept for its owner to copy again; lookups go through
 * its SHA-256 (`tokenHash`) and end with a constant-time comparison.
 * Not synced: sharing and joining need the network (spec §5.16).
 */
#[ORM\Entity(repositoryClass: ShareLinkRepository::class)]
#[ORM\Table(name: 'share_link')]
#[ORM\UniqueConstraint(name: 'uniq_share_link_token_hash', columns: ['token_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_share_link_active_owner', columns: ['owner_id'], options: ['where' => '(deleted_at IS NULL)'])]
class ShareLink implements TimestampableInterface, SoftDeletableInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    /** Spec §5.16 "plafond anti-abus": at most this many friendships per 24 h. */
    public const int MAX_JOINS_PER_DAY = 50;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 32)]
    private string $token;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    /** The adult who created it: the owner, or a child's manager. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    #[ORM\Column]
    private int $joinCount = 0;

    public function __construct(User $owner, ?User $createdBy)
    {
        $this->initializeId();
        $this->initializeTimestamps();
        $this->owner = $owner;
        $this->createdBy = $createdBy;
        // 128 random bits (spec §5.16), URL-safe base64 without padding: 22 characters.
        $this->token = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $this->tokenHash = self::hash($this->token);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function matches(string $token): bool
    {
        return hash_equals($this->token, $token);
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function isSuspended(): bool
    {
        return null !== $this->suspendedAt;
    }

    public function getSuspendedAt(): ?\DateTimeImmutable
    {
        return $this->suspendedAt;
    }

    public function suspend(): void
    {
        $this->suspendedAt = new \DateTimeImmutable();
    }

    public function getJoinCount(): int
    {
        return $this->joinCount;
    }

    public function recordJoin(): void
    {
        ++$this->joinCount;
    }
}
