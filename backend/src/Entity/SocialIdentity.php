<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\SocialProvider;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\SocialIdentityRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Links a user to a Google/Apple account (spec §5.1). The provider's
 * subject id is looked up on social login to find or auto-link an
 * existing account (same, verified email).
 */
#[ORM\Entity(repositoryClass: SocialIdentityRepository::class)]
#[ORM\Table(name: 'social_identity')]
#[ORM\UniqueConstraint(name: 'uniq_social_identity_provider_subject', columns: ['provider', 'provider_user_id'])]
class SocialIdentity implements TimestampableInterface, SoftDeletableInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(enumType: SocialProvider::class)]
    private SocialProvider $provider;

    #[ORM\Column(length: 255)]
    private string $providerUserId;

    public function __construct(User $user, SocialProvider $provider, string $providerUserId)
    {
        $this->initializeId();
        $this->initializeTimestamps();
        $this->user = $user;
        $this->provider = $provider;
        $this->providerUserId = $providerUserId;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProvider(): SocialProvider
    {
        return $this->provider;
    }

    public function getProviderUserId(): string
    {
        return $this->providerUserId;
    }
}
