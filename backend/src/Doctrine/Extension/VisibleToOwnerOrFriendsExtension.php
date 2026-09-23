<?php

declare(strict_types=1);

namespace App\Doctrine\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\OwnedEntityInterface;
use App\Entity\User;
use App\Repository\FriendshipRepository;
use App\Repository\UserRepository;
use App\Security\ActingContext;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Scopes every query on an OwnedEntityInterface resource (spec
 * §5.2/§5.4: a friend's profile — sizes, preferences, later ideas —
 * is readable, CLAUDE.md règle 1's 404-not-403 mechanism reused for
 * "owner or friend" rather than the golden rule itself).
 *
 * Collection queries default to "mine only", exactly like lot 1: a
 * friend's entries only join the list when the client explicitly asks
 * via `?userId=<uuid>`, so GET /profile_sizes stays predictable for
 * "my own profile" screens instead of silently mixing every friend's
 * entries together. Item queries (Get/Patch/Delete — the id is
 * already in the URL, there's nothing to default) always allow any
 * visible owner (self or friend): App\Security\Voter\OwnedEntityVoter
 * is what then blocks a friend from writing to it.
 */
final class VisibleToOwnerOrFriendsExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly ActingContext $acting,
        private readonly FriendshipRepository $friendships,
        private readonly UserRepository $users,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (!is_a($resourceClass, OwnedEntityInterface::class, true)) {
            return;
        }

        $user = $this->requireUser($queryBuilder);
        if (null === $user) {
            return;
        }

        $requestedUserId = $this->requestStack->getCurrentRequest()?->query->get('userId');
        $alias = $queryBuilder->getRootAliases()[0];

        if (null === $requestedUserId) {
            $this->restrictTo($queryBuilder, $alias, $user->getId());

            return;
        }

        if (!\is_string($requestedUserId) || !Uuid::isValid($requestedUserId)) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $requested = Uuid::fromString($requestedUserId);
        $isSelf = $requested->equals($user->getId());

        if (!$isSelf && !\in_array($requestedUserId, $this->visibleOwnerIds($user), true)) {
            // Not self, not a friend: same empty result as a non-existent
            // user id — never reveals whether that account exists.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $this->restrictTo($queryBuilder, $alias, $requested);
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        if (!is_a($resourceClass, OwnedEntityInterface::class, true)) {
            return;
        }

        $user = $this->requireUser($queryBuilder);
        if (null === $user) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $friendIds = $this->visibleOwnerIds($user);

        if ([] === $friendIds) {
            $this->restrictTo($queryBuilder, $alias, $user->getId());

            return;
        }

        $queryBuilder
            ->andWhere(\sprintf('%1$s.user = :visible_to_current_user OR %1$s.user IN (:visible_to_current_user_friends)', $alias))
            ->setParameter('visible_to_current_user', $user->getId(), 'uuid')
            ->setParameter('visible_to_current_user_friends', $friendIds);
    }

    /**
     * Friends, plus — for a manager not acting — their managed profiles
     * (spec §5.15: the manager sees everything about the child).
     *
     * @return string[]
     */
    private function visibleOwnerIds(User $user): array
    {
        $ids = $this->friendships->findAcceptedFriendIds($user);
        foreach ($this->users->findBy(['managedBy' => $user]) as $child) {
            $ids[] = $child->getId()->toRfc4122();
        }

        return $ids;
    }

    private function requireUser(QueryBuilder $queryBuilder): ?User
    {
        // The acting profile (X-Acting-As) when there is one.
        $user = $this->acting->actor();
        if ($user instanceof User) {
            return $user;
        }

        $queryBuilder->andWhere('1 = 0');

        return null;
    }

    private function restrictTo(QueryBuilder $queryBuilder, string $alias, Uuid $userId): void
    {
        $queryBuilder
            ->andWhere(\sprintf('%s.user = :visible_to_current_user', $alias))
            ->setParameter('visible_to_current_user', $userId, 'uuid');
    }
}
