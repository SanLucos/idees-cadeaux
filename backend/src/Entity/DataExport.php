<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\DataExportRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * "Export de mes données" (spec §5.13): one archive of an account — or
 * of a child profile, requested by its manager — built asynchronously,
 * downloadable for 48 h through a link sent by email. The link's token
 * is only kept hashed. Not synced; purged once expired.
 */
#[ORM\Entity(repositoryClass: DataExportRepository::class)]
#[ORM\Table(name: 'data_export')]
class DataExport implements TimestampableInterface, SoftDeletableInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    public const string TTL = '48 hours';

    public const string PENDING = 'pending';
    public const string READY = 'ready';
    public const string FAILED = 'failed';

    /** Whose data: the account itself, or a child profile. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $subject;

    /** Who receives the link: the account, or the child's manager. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $requestedBy;

    #[ORM\Column(length: 10)]
    private string $status = self::PENDING;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $path = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tokenHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct(User $subject, User $requestedBy)
    {
        $this->initializeId();
        $this->initializeTimestamps();
        $this->subject = $subject;
        $this->requestedBy = $requestedBy;
    }

    public function getSubject(): User
    {
        return $this->subject;
    }

    public function getRequestedBy(): User
    {
        return $this->requestedBy;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * @return string the download token, to put in the emailed link only
     */
    public function markReady(string $path): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $this->status = self::READY;
        $this->path = $path;
        $this->tokenHash = hash('sha256', $token);
        $this->expiresAt = new \DateTimeImmutable('+'.self::TTL);

        return $token;
    }

    public function markFailed(): void
    {
        $this->status = self::FAILED;
    }

    public function isDownloadable(string $token): bool
    {
        return self::READY === $this->status
            && null !== $this->tokenHash && hash_equals($this->tokenHash, hash('sha256', $token))
            && null !== $this->expiresAt && $this->expiresAt > new \DateTimeImmutable();
    }
}
