<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\Enum\FriendshipOrigin;
use App\Entity\Friendship;
use App\Entity\User;
use App\Service\FamilyFriendships;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps the family friendships (spec §11 décision 59) in line, whatever
 * path changed what they depend on: a friendship of a manager made or
 * ended (request, link, conversion, removal), a child's option, its
 * managers, its deletion or its conversion. Done after the flush, then
 * flushed in turn; the friendships it writes don't trigger it again.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class FamilyFriendshipsListener implements ResetInterface
{
    private const array CHILD_FIELDS = ['shareWithManagerFriends', 'managedBy', 'coManagedBy', 'deletionScheduledAt', 'type'];

    /** @var array<string, User> */
    private array $adults = [];

    /** @var array<string, User> */
    private array $children = [];

    private bool $running = false;

    public function __construct(private readonly FamilyFriendships $family)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->running) {
            return;
        }

        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()] as $entity) {
            if ($entity instanceof Friendship && FriendshipOrigin::Family !== $entity->getOrigin()) {
                foreach ([$entity->getRequester(), $entity->getAddressee()] as $user) {
                    if (!$user->isManaged()) {
                        $this->adults[$user->getId()->toRfc4122()] = $user;
                    }
                }
            }
            if ($entity instanceof User && [] !== array_intersect(self::CHILD_FIELDS, array_keys($uow->getEntityChangeSet($entity)))) {
                $this->children[$entity->getId()->toRfc4122()] = $entity;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->running || ([] === $this->adults && [] === $this->children)) {
            return;
        }

        $this->running = true;
        try {
            [$adults, $children] = [$this->adults, $this->children];
            $this->reset();
            foreach ($adults as $adult) {
                $this->family->refreshChildrenOf($adult);
            }
            foreach ($children as $child) {
                $this->family->refresh($child);
            }
            $args->getObjectManager()->flush();
        } finally {
            $this->running = false;
        }
    }

    public function reset(): void
    {
        $this->adults = [];
        $this->children = [];
    }
}
