<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * One value a size has had, since `createdAt` (spec §5.2, §11 décision
 * 51). Written by ProfileSize alone, whenever its value changes, and
 * removed one by one by the owner; never a resource of its own. Read
 * through App\Serializer\ProfileSizeHistoryView, by the profile's owner
 * or the manager of a child profile — never by friends, who only see
 * the current value.
 */
#[ORM\Entity]
#[ORM\Table(name: 'profile_size_history')]
class ProfileSizeHistory implements TimestampableInterface, SoftDeletableInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    #[ORM\ManyToOne(targetEntity: ProfileSize::class, inversedBy: 'history')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProfileSize $size;

    #[ORM\Column(length: 60)]
    private string $value;

    public function __construct(ProfileSize $size, string $value)
    {
        $this->initializeId();
        $this->initializeTimestamps();
        $this->size = $size;
        $this->value = $value;
    }

    public function getSize(): ProfileSize
    {
        return $this->size;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
