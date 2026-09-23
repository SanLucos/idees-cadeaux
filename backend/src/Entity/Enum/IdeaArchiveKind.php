<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * Spec §5.4 "Archivage": a personal idea is marked "received" by its
 * owner; a suggestion is marked "gifted" (offert) by its author — and,
 * from lot 4, by its reserver or the contribution's initiator.
 */
enum IdeaArchiveKind: string
{
    case Received = 'received';
    case Gifted = 'gifted';
}
