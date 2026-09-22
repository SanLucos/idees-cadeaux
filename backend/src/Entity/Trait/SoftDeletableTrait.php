<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

trait SoftDeletableTrait
{
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function markDeleted(\DateTimeImmutable $at = new \DateTimeImmutable()): void
    {
        $this->deletedAt = $at;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }
}
