<?php

declare(strict_types=1);

namespace App\Doctrine\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\OwnedEntityInterface;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

final class OwnedByCurrentUserExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $this->apply($queryBuilder, $resourceClass);
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        $this->apply($queryBuilder, $resourceClass);
    }

    private function apply(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if (!is_a($resourceClass, OwnedEntityInterface::class, true)) {
            return;
        }

        $user = $this->security->getUser();
        $alias = $queryBuilder->getRootAliases()[0];

        if (!$user instanceof User) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $queryBuilder
            ->andWhere(\sprintf('%s.user = :owned_by_current_user', $alias))
            ->setParameter('owned_by_current_user', $user->getId(), 'uuid');
    }
}
