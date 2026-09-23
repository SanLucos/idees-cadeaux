<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\DeviceTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A push token (spec §5.11): registered at sign-in, removed at
 * sign-out, dropped when FCM reports it invalid.
 */
#[ORM\Entity(repositoryClass: DeviceTokenRepository::class)]
#[ORM\Table(name: 'device_token')]
#[ORM\UniqueConstraint(name: 'uniq_device_token', columns: ['token'])]
class DeviceToken implements TimestampableInterface, SoftDeletableInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    public const array PLATFORMS = ['ios', 'android', 'web'];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 10)]
    private string $platform;

    #[ORM\Column(length: 512)]
    private string $token;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(User $user, string $platform, string $token)
    {
        $this->initializeId();
        $this->initializeTimestamps();
        $this->user = $user;
        $this->platform = $platform;
        $this->token = $token;
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /** A token moves to whoever signs in on that device last. */
    public function claim(User $user, string $platform): void
    {
        $this->user = $user;
        $this->platform = $platform;
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function getToken(): string
    {
        return $this->token;
    }
}
