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
 * sign-out, dropped when the push service reports it invalid.
 *
 * ios / android: an FCM token. web (spec §11 décision 70): a Web Push
 * subscription of the web app installed from the browser — the token is
 * its endpoint, with the browser's public key and auth secret needed to
 * encrypt the payload (RFC 8291).
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

    /** Web Push only: the subscription's P-256 public key (base64url). */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $webPushP256dh = null;

    /** Web Push only: the subscription's auth secret (base64url). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $webPushAuth = null;

    /**
     * @param array{p256dh: string, auth: string}|null $webPushKeys
     */
    public function __construct(User $user, string $platform, string $token, ?array $webPushKeys = null)
    {
        $this->initializeId();
        $this->initializeTimestamps();
        $this->user = $user;
        $this->platform = $platform;
        $this->token = $token;
        $this->lastSeenAt = new \DateTimeImmutable();
        $this->setWebPushKeys($webPushKeys);
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /** A token moves to whoever signs in on that device last. */
    /**
     * @param array{p256dh: string, auth: string}|null $webPushKeys
     */
    public function claim(User $user, string $platform, ?array $webPushKeys = null): void
    {
        $this->user = $user;
        $this->platform = $platform;
        $this->lastSeenAt = new \DateTimeImmutable();
        $this->setWebPushKeys($webPushKeys);
    }

    public function isWebPush(): bool
    {
        return 'web' === $this->platform;
    }

    /**
     * @return array{p256dh: string, auth: string}|null
     */
    public function getWebPushKeys(): ?array
    {
        return null === $this->webPushP256dh || null === $this->webPushAuth
            ? null
            : ['p256dh' => $this->webPushP256dh, 'auth' => $this->webPushAuth];
    }

    /**
     * @param array{p256dh: string, auth: string}|null $keys
     */
    private function setWebPushKeys(?array $keys): void
    {
        $this->webPushP256dh = $keys['p256dh'] ?? null;
        $this->webPushAuth = $keys['auth'] ?? null;
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
