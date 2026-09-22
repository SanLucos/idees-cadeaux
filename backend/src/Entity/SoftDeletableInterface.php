<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Logical deletion (CLAUDE.md règle 9): deletedAt drives /sync
 * tombstones instead of a hard DELETE. Filtered out of every query by
 * App\Doctrine\SoftDeleteFilter unless explicitly disabled (e.g. /sync).
 */
interface SoftDeletableInterface
{
    public function getDeletedAt(): ?\DateTimeImmutable;

    public function markDeleted(\DateTimeImmutable $at = new \DateTimeImmutable()): void;

    public function isDeleted(): bool;
}
