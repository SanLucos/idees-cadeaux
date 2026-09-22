<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Every entity carries createdAt/updatedAt (CLAUDE.md règle 9): the
 * foundation for last-write-wins conflict resolution in /sync (spec §8).
 */
interface TimestampableInterface
{
    public function getCreatedAt(): \DateTimeImmutable;

    public function getUpdatedAt(): \DateTimeImmutable;

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void;
}
