<?php

declare(strict_types=1);

namespace App\Sync;

use App\Entity\Comment;
use App\Entity\Enum\IdeaVisibility;
use App\Entity\Friendship;
use App\Entity\Idea;
use App\Entity\Notification;
use App\Entity\Occasion;
use App\Entity\ProfilePreference;
use App\Entity\ProfileSize;
use App\Entity\User;
use App\Repository\FriendshipRepository;
use App\Repository\UserRepository;
use App\Security\IdeaAccess;
use App\Serializer\FriendshipNormalizer;
use App\Serializer\IdeaNormalizer;
use App\Serializer\InteractionNormalizer;
use App\Serializer\ManagedProfileNormalizer;
use App\Serializer\UserNormalizer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What an adult's devices hold offline (spec §8, §11 décisions 32–33):
 * every document they may read — in the exact API view (owner, friend,
 * manager) — for themselves and their child profiles (list, profile,
 * friendships).
 *
 * Each type is an inventory `id => version`; a version moves whenever
 * anything shown in the document changes, soft-deleted interactions
 * included (a cancelled reservation changes the idea's version).
 * Visibility rules are the API's: the queries mirror IdeaRepository's
 * list queries and IdeaAccess (checked by tests/Visibility/SyncVisibilityTest).
 */
final class SyncService
{
    public const array TYPES = ['user', 'managed_profile', 'friendship', 'idea', 'comment', 'profile_size', 'profile_preference', 'notification', 'occasion'];
    private const int NOTIFICATIONS_KEPT = 200;

    /** @var array<string, array<string, array{version: string, entity: object, viewer?: User}>>|null */
    private ?array $inventory = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FriendshipRepository $friendships,
        private readonly UserRepository $users,
        private readonly IdeaNormalizer $ideaNormalizer,
        private readonly InteractionNormalizer $interactionNormalizer,
        private readonly UserNormalizer $userNormalizer,
        private readonly ManagedProfileNormalizer $managedProfileNormalizer,
        private readonly FriendshipNormalizer $friendshipNormalizer,
    ) {
    }

    /**
     * @return array<string, array<string, array{version: string, entity: object, viewer?: User}>> type => id => entry
     */
    public function inventory(User $me): array
    {
        if (null !== $this->inventory) {
            return $this->inventory;
        }

        $children = $this->users->findBy(['managedBy' => $me]);
        $friends = array_map(static fn (Friendship $f) => $f->otherParty($me), $this->friendships->findAccepted($me));

        $inventory = array_fill_keys(self::TYPES, []);

        foreach ([$me, ...$friends, ...$children] as $user) {
            $inventory['user'][$user->getId()->toRfc4122()] = ['version' => self::v($user->getUpdatedAt()), 'entity' => $user];
        }
        foreach ($children as $child) {
            $inventory['managed_profile'][$child->getId()->toRfc4122()] = ['version' => self::v($child->getUpdatedAt()), 'entity' => $child];
        }

        foreach ([$me, ...$children] as $viewer) {
            foreach ([...$this->friendships->findAccepted($viewer), ...$this->friendships->findPendingIncoming($viewer), ...$this->friendships->findOutgoingVisible($viewer)] as $friendship) {
                $inventory['friendship'][$friendship->getId()->toRfc4122()] = [
                    'version' => self::v(max($friendship->getUpdatedAt(), $friendship->otherParty($viewer)->getUpdatedAt())),
                    'entity' => $friendship,
                    'viewer' => $viewer,
                ];
            }
        }

        $ideas = $this->visibleIdeas($me, $friends, $children);
        $interactionVersions = $this->interactionVersions(array_keys($ideas));
        foreach ($ideas as $id => $idea) {
            $inventory['idea'][$id] = [
                'version' => self::v(max($idea->getUpdatedAt(), $idea->getAuthor()->getUpdatedAt(), $idea->getOwner()->getUpdatedAt()), $interactionVersions[$id] ?? null),
                'entity' => $idea,
            ];
        }

        // Comments: only where the interactions are visible — never on one's own list (règle 1).
        $withInteractions = array_keys(array_filter($ideas, static fn (Idea $i) => $i->getOwner() !== $me && $i->isPublished()));
        foreach ($this->findIn(Comment::class, 'idea', $withInteractions) as $comment) {
            $inventory['comment'][$comment->getId()->toRfc4122()] = ['version' => self::v(max($comment->getUpdatedAt(), $comment->getAuthor()->getUpdatedAt())), 'entity' => $comment];
        }

        $profiles = array_map(static fn (User $u) => $u->getId()->toRfc4122(), [$me, ...$friends, ...$children]);
        foreach ([ProfileSize::class => 'profile_size', ProfilePreference::class => 'profile_preference'] as $class => $type) {
            foreach ($this->findIn($class, 'user', $profiles) as $entry) {
                $inventory[$type][$entry->getId()->toRfc4122()] = ['version' => self::v($entry->getUpdatedAt()), 'entity' => $entry];
            }
        }

        foreach ($this->em->getRepository(Notification::class)->findBy(['user' => $me, 'inApp' => true], ['createdAt' => 'DESC'], self::NOTIFICATIONS_KEPT) as $notification) {
            $inventory['notification'][$notification->getId()->toRfc4122()] = ['version' => self::v($notification->getUpdatedAt()), 'entity' => $notification];
        }
        foreach ($this->em->getRepository(Occasion::class)->findAll() as $occasion) {
            $inventory['occasion'][$occasion->getCode()] = ['version' => self::v($occasion->getUpdatedAt()), 'entity' => $occasion];
        }

        return $this->inventory = $inventory;
    }

    /**
     * Documents for these inventory entries, in their API view.
     *
     * @param array<string, array{version: string, entity: object, viewer?: User}> $entries
     *
     * @return list<array<string, mixed>>
     */
    public function documents(string $type, array $entries, User $me): array
    {
        if ('idea' === $type) {
            return $this->ideaDocuments(array_map(static fn (array $e) => $e['entity'], $entries), $me, $entries);
        }

        $docs = [];
        foreach ($entries as $id => $entry) {
            $entity = $entry['entity'];
            $doc = match ($type) {
                'user' => $entity === $me ? $this->userNormalizer->normalize($entity) : $this->userNormalizer->normalizeForFriend($entity),
                'managed_profile' => $this->managedProfileNormalizer->normalize($entity),
                'friendship' => $this->friendshipNormalizer->normalize($entity, $entry['viewer']) + ['profileId' => $entry['viewer']->getId()->toRfc4122()],
                'comment' => $this->interactionNormalizer->comment($entity, $me),
                'profile_size' => [
                    'id' => $id, '@id' => '/api/profile_sizes/'.$id, 'userId' => $entity->getUser()->getId()->toRfc4122(),
                    'label' => $entity->getLabel(), 'value' => $entity->getValue(), 'note' => $entity->getNote(), 'sortOrder' => $entity->getSortOrder(),
                ],
                'profile_preference' => [
                    'id' => $id, '@id' => '/api/profile_preferences/'.$id, 'userId' => $entity->getUser()->getId()->toRfc4122(),
                    'category' => $entity->getCategory()->value, 'label' => $entity->getLabel(), 'value' => $entity->getValue(),
                ],
                'notification' => [
                    'id' => $id, 'type' => $entity->getType()->value, 'payload' => $entity->getPayload(),
                    'readAt' => $entity->getReadAt()?->format(\DATE_ATOM), 'createdAt' => $entity->getCreatedAt()->format(\DATE_ATOM),
                ],
                'occasion' => ['id' => $id, 'code' => $id, 'translationKey' => $entity->getTranslationKey(), 'sortOrder' => $entity->getSortOrder()],
            };
            $docs[] = $doc + ['id' => $id, '_version' => $entry['version']];
        }

        return $docs;
    }

    /**
     * Friend lists + own list + own drafts elsewhere + children's lists:
     * the union of what GET /users/{id}/ideas and /ideas/private return.
     *
     * @param User[] $friends
     * @param User[] $children
     *
     * @return array<string, Idea>
     */
    private function visibleIdeas(User $me, array $friends, array $children): array
    {
        $ids = static fn (array $users): array => array_map(static fn (User $u) => $u->getId()->toRfc4122(), $users);
        $friendIds = $ids($friends) ?: ['00000000-0000-0000-0000-000000000000'];
        $childIds = $ids($children) ?: ['00000000-0000-0000-0000-000000000000'];

        $qb = $this->em->getRepository(Idea::class)->createQueryBuilder('i')
            ->addSelect('a', 'o')->join('i.author', 'a')->join('i.owner', 'o')
            ->andWhere(implode(' OR ', [
                // Vue propriétaire: my own ideas, never a suggestion.
                '(i.owner = :me AND i.author = :me)',
                // Vue ami: friends' published ideas and suggestions, and my drafts for them.
                '(i.owner IN (:friends) AND (i.visibility = :published OR i.author = :me))',
                // Vue gestionnaire: my children's lists, friends' drafts excluded (règle 2).
                '(i.owner IN (:children) AND (i.visibility = :published OR i.author = i.owner OR i.author = :me))',
                // My drafts for anyone else (e.g. an ex-friend): mine alone to see.
                '(i.author = :me AND i.visibility = :private)',
            ]))
            ->setParameter('me', $me->getId(), 'uuid')
            ->setParameter('friends', $friendIds)
            ->setParameter('children', $childIds)
            ->setParameter('published', IdeaVisibility::Published)
            ->setParameter('private', IdeaVisibility::Private);

        $ideas = [];
        foreach ($qb->getQuery()->getResult() as $idea) {
            $ideas[$idea->getId()->toRfc4122()] = $idea;
        }

        return $ideas;
    }

    /**
     * @param array<string, Idea>                                   $ideas
     * @param array<string, array{version: string, entity: object}> $entries
     *
     * @return list<array<string, mixed>>
     */
    private function ideaDocuments(array $ideas, User $me, array $entries): array
    {
        $managed = $owned = $friendly = [];
        foreach ($ideas as $idea) {
            if (IdeaAccess::readsAsManager($idea->getOwner(), $me)) {
                $managed[] = $idea;
            } elseif ($idea->getOwner() === $me && !$idea->isSuggestion()) {
                $owned[] = $idea;
            } else {
                $friendly[] = $idea;
            }
        }

        $docs = [
            ...array_map($this->ideaNormalizer->normalizeForOwner(...), $owned),
            ...$this->ideaNormalizer->normalizeManyForFriend($friendly, $me),
        ];
        // A child's list is only edited while acting as the child (X-Acting-As):
        // compute canEdit / isMine from the child's side, exactly as the API
        // answers when acting. Interactions stay the manager's (humanBehind).
        $byChild = [];
        foreach ($managed as $idea) {
            $byChild[$idea->getOwner()->getId()->toRfc4122()][] = $idea;
        }
        foreach ($byChild as $ideasOfChild) {
            array_push($docs, ...$this->ideaNormalizer->normalizeManyForManager($ideasOfChild, $ideasOfChild[0]->getOwner()));
        }

        return array_map(static fn (array $d) => $d + ['_version' => $entries[$d['id']]['version']], $docs);
    }

    /**
     * Latest change among an idea's interactions, soft-deleted ones
     * included: plain SQL on purpose (no soft-delete filter).
     *
     * @param string[] $ideaIds
     *
     * @return array<string, \DateTimeImmutable>
     */
    private function interactionVersions(array $ideaIds): array
    {
        if ([] === $ideaIds) {
            return [];
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT idea_id, MAX(updated_at) AS v FROM (
                SELECT idea_id, updated_at FROM reservation WHERE idea_id IN (:ids)
                UNION ALL SELECT idea_id, updated_at FROM comment WHERE idea_id IN (:ids)
                UNION ALL SELECT idea_id, updated_at FROM reaction WHERE idea_id IN (:ids)
                UNION ALL SELECT idea_id, updated_at FROM contribution WHERE idea_id IN (:ids)
                UNION ALL SELECT c.idea_id, p.updated_at FROM contribution_pledge p JOIN contribution c ON c.id = p.contribution_id WHERE c.idea_id IN (:ids)
            ) x GROUP BY idea_id',
            ['ids' => $ideaIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $versions = [];
        foreach ($rows as $row) {
            $versions[(string) $row['idea_id']] = new \DateTimeImmutable($row['v']);
        }

        return $versions;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param string[]        $ids
     *
     * @return T[]
     */
    private function findIn(string $class, string $field, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->em->getRepository($class)->createQueryBuilder('x')
            ->andWhere("x.{$field} IN (:ids)")->setParameter('ids', $ids)
            ->getQuery()->getResult();
    }

    /** Version string: comparable as text. */
    public static function v(?\DateTimeInterface ...$dates): string
    {
        $latest = max(array_map(static fn (?\DateTimeInterface $d) => $d?->getTimestamp() ?? 0, $dates));

        return gmdate('Y-m-d\TH:i:s\Z', $latest);
    }
}
