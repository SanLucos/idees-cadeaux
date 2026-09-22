<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * UUID v7 primary key, generatable by the client for idempotent,
 * offline-first writes (CLAUDE.md règle 9 / spec section 8).
 */
trait IdentifiableTrait
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    /**
     * Call from the entity constructor. Pass a client-supplied UUID to
     * keep the same id across an offline create and its later sync,
     * or leave null to generate one server-side.
     */
    private function initializeId(?Uuid $id = null): void
    {
        $this->id = $id ?? Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }
}
