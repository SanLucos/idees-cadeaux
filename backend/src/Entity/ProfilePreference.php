<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\ProfilePreferenceInput;
use App\Entity\Enum\ProfilePreferenceCategory;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\ProfilePreferenceRepository;
use App\State\ProfilePreferenceCreateProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A free preference entry ("goût", "marque", "autre"), spec §5.2.
 * Every operation is scoped to the current user by
 * App\Doctrine\Extension\OwnedByCurrentUserExtension: someone else's
 * entry, or a friend's (until lot 2 adds friend-read), is a 404, not
 * a 403.
 */
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(input: ProfilePreferenceInput::class, processor: ProfilePreferenceCreateProcessor::class),
        new Patch(),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['profile_preference:read']],
    denormalizationContext: ['groups' => ['profile_preference:write']],
)]
#[ORM\Entity(repositoryClass: ProfilePreferenceRepository::class)]
#[ORM\Table(name: 'profile_preference')]
class ProfilePreference implements TimestampableInterface, SoftDeletableInterface, OwnedEntityInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[Groups(['profile_preference:read', 'profile_preference:write'])]
    #[ORM\Column(enumType: ProfilePreferenceCategory::class)]
    private ProfilePreferenceCategory $category;

    #[Groups(['profile_preference:read', 'profile_preference:write'])]
    #[ORM\Column(length: 60)]
    private string $label;

    #[Groups(['profile_preference:read', 'profile_preference:write'])]
    #[ORM\Column(length: 200)]
    private string $value;

    public function __construct(User $user, ProfilePreferenceCategory $category, string $label, string $value)
    {
        $this->initializeId();
        $this->initializeTimestamps();
        $this->user = $user;
        $this->category = $category;
        $this->label = $label;
        $this->value = $value;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCategory(): ProfilePreferenceCategory
    {
        return $this->category;
    }

    public function setCategory(ProfilePreferenceCategory $category): void
    {
        $this->category = $category;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): void
    {
        $this->value = $value;
    }
}
