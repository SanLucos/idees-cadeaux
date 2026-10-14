<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\ProfileSizeInput;
use App\Entity\Enum\ProfileSizeUnit;
use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Exception\ApiProblemException;
use App\Repository\ProfileSizeRepository;
use App\State\ProfileSizeCreateProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * A free "label + value" entry with an optional unit (e.g. "Pointure : 42
 * EU"), spec §5.2 and §11 décision 57.
 * Read (Get/GetCollection) is scoped to the owner and their accepted
 * friends by App\Doctrine\Extension\VisibleToOwnerOrFriendsExtension —
 * anyone else gets a 404, not a 403. Writes (Patch/Delete) are further
 * restricted to the owner alone by App\Security\Voter\OwnedEntityVoter.
 *
 * Every value it has had is kept in `history` (spec §11 décision 51),
 * which friends never get: App\Serializer\ProfileSizeNormalizer adds it
 * for the owner, or the manager of a child profile, alone. The owner
 * removes a past value with App\Controller\ProfileSizeHistoryController.
 */
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(input: ProfileSizeInput::class, processor: ProfileSizeCreateProcessor::class),
        new Patch(security: "is_granted('OWNER', object)"),
        new Delete(security: "is_granted('OWNER', object)"),
    ],
    normalizationContext: ['groups' => ['profile_size:read']],
    order: ['sortOrder' => 'ASC', 'createdAt' => 'ASC'],
    // The whole list fits on one page: capped at 100 per user (spec §5.2).
    paginationItemsPerPage: 100,
    denormalizationContext: ['groups' => ['profile_size:write']],
)]
#[ORM\Entity(repositoryClass: ProfileSizeRepository::class)]
#[ORM\Table(name: 'profile_size')]
class ProfileSize implements TimestampableInterface, SoftDeletableInterface, OwnedEntityInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[Groups(['profile_size:read', 'profile_size:write'])]
    #[ORM\Column(length: 60)]
    private string $label;

    #[Groups(['profile_size:read', 'profile_size:write'])]
    #[ORM\Column(length: 60)]
    private string $value;

    /** A ProfileSizeUnit value, or null: no unit. */
    #[Groups(['profile_size:read', 'profile_size:write'])]
    #[ORM\Column(length: 4, nullable: true)]
    private ?string $unit = null;

    #[Groups(['profile_size:read', 'profile_size:write'])]
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $note = null;

    #[Groups(['profile_size:read', 'profile_size:write'])]
    #[ORM\Column]
    private int $sortOrder = 0;

    /** @var Collection<int, ProfileSizeHistory> oldest first; the last one is the current value */
    #[ORM\OneToMany(targetEntity: ProfileSizeHistory::class, mappedBy: 'size', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $history;

    public function __construct(User $user, string $label, string $value, ?string $note = null, int $sortOrder = 0, ?Uuid $id = null, ?string $unit = null)
    {
        $this->initializeId($id);
        $this->initializeTimestamps();
        $this->user = $user;
        $this->label = $label;
        $this->value = $value;
        $this->note = $note;
        $this->sortOrder = $sortOrder;
        $this->unit = self::checkedUnit($unit);
        $this->history = new ArrayCollection([new ProfileSizeHistory($this, $value, $this->unit)]);
    }

    public function getUser(): User
    {
        return $this->user;
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
        if ($value === $this->value) {
            return;
        }

        $this->value = $value;
        $this->history->add(new ProfileSizeHistory($this, $value, $this->unit));
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    /**
     * A new unit alone is no new value (spec §11 décision 57): it
     * corrects the current one, whose history entry takes it too.
     */
    public function setUnit(?string $unit): void
    {
        $this->unit = self::checkedUnit($unit);
        $current = $this->history->last();
        if ($current instanceof ProfileSizeHistory) {
            $current->setUnit($this->unit);
        }
    }

    private static function checkedUnit(?string $unit): ?string
    {
        if (null === $unit || '' === $unit) {
            return null;
        }
        if (null === ProfileSizeUnit::tryFrom($unit)) {
            throw new ApiProblemException('profile_size.unit_invalid', 'Unknown unit.', 422);
        }

        return $unit;
    }

    /**
     * @return list<ProfileSizeHistory> oldest first
     */
    public function getHistory(): array
    {
        return array_values($this->history->toArray());
    }

    /**
     * Removes a past value from the history, for good. The current value
     * — the last entry — stays: it goes when the value changes.
     *
     * @return bool false when `$id` is the current value's entry
     */
    public function removeFromHistory(Uuid $id): bool
    {
        $entries = $this->getHistory();
        $current = array_pop($entries);
        if (null !== $current && $current->getId()->equals($id)) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry->getId()->equals($id)) {
                $this->history->removeElement($entry);
                // What is shown of this size changed: devices fetch it again.
                $this->setUpdatedAt(new \DateTimeImmutable());
            }
        }

        return true;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }
}
