<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\SoftDeletableInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Excludes logically deleted rows (deletedAt IS NOT NULL) from every
 * query by default. Endpoints that need tombstones (/sync, RGPD export
 * of one's own deletions) must disable it explicitly:
 *   $em->getFilters()->disable('soft_deleteable');
 */
final class SoftDeleteFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$targetEntity->getReflectionClass()->implementsInterface(SoftDeletableInterface::class)) {
            return '';
        }

        return sprintf('%s.deleted_at IS NULL', $targetTableAlias);
    }
}
