<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\IdentifiableTrait;
use App\Entity\Trait\SoftDeletableTrait;
use App\Entity\Trait\TimestampableTrait;
use App\Repository\OccasionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Server-managed reference table (spec §5.4): 14 predefined occasions,
 * never user-editable in v1, labels translated client-side from
 * `translationKey`. Seeded by migration.
 */
#[ORM\Entity(repositoryClass: OccasionRepository::class)]
#[ORM\Table(name: 'occasion')]
#[ORM\UniqueConstraint(name: 'uniq_occasion_code', columns: ['code'])]
class Occasion implements TimestampableInterface, SoftDeletableInterface
{
    use IdentifiableTrait;
    use TimestampableTrait;
    use SoftDeletableTrait;

    #[ORM\Column(length: 40)]
    private string $code;

    #[ORM\Column(length: 80)]
    private string $translationKey;

    #[ORM\Column]
    private int $sortOrder;

    public function __construct(string $code, string $translationKey, int $sortOrder)
    {
        $this->initializeId();
        $this->initializeTimestamps();
        $this->code = $code;
        $this->translationKey = $translationKey;
        $this->sortOrder = $sortOrder;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getTranslationKey(): string
    {
        return $this->translationKey;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }
}
