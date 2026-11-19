<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\IdeaListFilter;
use App\Entity\Enum\IdeaStatus;
use App\Entity\Enum\IdeaVisibility;
use App\Entity\Idea;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * List queries apply the same rules as App\Security\IdeaAccess::viewOf,
 * in SQL. Keep the two in step: tests/Visibility/IdeaVisibilityTest
 * checks both paths.
 *
 * An idea has one or more recipients (IdeaRecipient, décision 69): "X's
 * list" is every idea X is a recipient of. A suggestion is an idea whose
 * author isn't among its recipients; it never shows to any recipient,
 * whichever list they read it from.
 *
 * @extends ServiceEntityRepository<Idea>
 */
class IdeaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Idea::class);
    }

    /** DQL: `:param` is one of idea `i`'s recipients. */
    public static function hasRecipient(string $param, string $idea = 'i'): string
    {
        $r = 'r_'.$param;

        return "EXISTS (SELECT 1 FROM App\\Entity\\IdeaRecipient {$r} WHERE {$r}.idea = {$idea} AND {$r}.user = :{$param} AND {$r}.deletedAt IS NULL)";
    }

    /**
     * DQL: idea `i`'s author is one of its recipients (a personal idea,
     * not a suggestion). Each use in one query takes its own `$tag`.
     */
    public static function isPersonal(string $tag = 'p', string $idea = 'i'): string
    {
        $r = 'r_author_'.$tag;

        return "EXISTS (SELECT 1 FROM App\\Entity\\IdeaRecipient {$r} WHERE {$r}.idea = {$idea} AND {$r}.user = {$idea}.author AND {$r}.deletedAt IS NULL)";
    }

    /**
     * Vue propriétaire: the personal ideas I'm a recipient of, published
     * or my own drafts. A suggestion on my list never appears here,
     * whatever its state; nor does another recipient's draft (règle 2).
     *
     * @return Paginator<Idea>
     */
    public function findOwnerView(User $me, IdeaListFilter $filter): Paginator
    {
        $qb = $this->ownerViewQuery($me);

        return $this->paginate($this->applyFilter($qb, $filter), $filter);
    }

    private function ownerViewQuery(User $me): QueryBuilder
    {
        return $this->createQueryBuilder('i')
            ->andWhere(self::hasRecipient('me'))
            ->andWhere(self::isPersonal())
            ->andWhere('i.visibility = :ov_published OR i.author = :me')
            ->setParameter('me', $me->getId(), 'uuid')
            ->setParameter('ov_published', IdeaVisibility::Published);
    }

    /**
     * Vue invité (spec §5.16): the owner's own published, active ideas
     * — never a suggestion, a draft or an archive, whatever the filter.
     *
     * @return Paginator<Idea>
     */
    public function findGuestView(User $owner, IdeaListFilter $filter): Paginator
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere(self::hasRecipient('owner'))
            ->andWhere(self::isPersonal())
            ->andWhere('i.visibility = :published')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->setParameter('published', IdeaVisibility::Published);

        return $this->paginate($this->applyFilter($qb, new IdeaListFilter(
            status: IdeaStatus::Active,
            occasion: $filter->occasion,
            sort: $filter->sort,
            page: $filter->page,
            itemsPerPage: $filter->itemsPerPage,
        )), $filter);
    }

    /**
     * The occasions used in the guest view, for its filter chips.
     *
     * @return string[]
     */
    public function findGuestOccasionCodes(User $owner): array
    {
        return array_column($this->createQueryBuilder('i')
            ->select('DISTINCT o.code AS code, o.sortOrder AS sortOrder')
            ->innerJoin('i.occasion', 'o')
            ->andWhere(self::hasRecipient('owner'))
            ->andWhere(self::isPersonal())
            ->andWhere('i.visibility = :published AND i.status = :active')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->setParameter('published', IdeaVisibility::Published)
            ->setParameter('active', IdeaStatus::Active)
            ->orderBy('o.sortOrder', 'ASC')
            ->getQuery()
            ->getArrayResult(), 'code');
    }

    /**
     * Vue ami: `$owner`'s personal ideas and their friends' suggestions,
     * published — plus my own drafts for them (visible to me alone).
     * The caller has already checked `$viewer` is `$owner`'s friend.
     * `$human` (the adult behind `$viewer`) may be a co-recipient of some
     * of them: their personal ones come back (owner view, decided per
     * idea by the normalizer), suggestions never (règle d'or).
     *
     * @return Paginator<Idea>
     */
    public function findFriendView(User $owner, User $viewer, IdeaListFilter $filter, ?User $human = null): Paginator
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere(self::hasRecipient('owner'))
            ->andWhere('i.visibility = :published OR i.author = :viewer')
            ->andWhere(\sprintf('NOT (%s) OR %s', self::hasRecipient('human'), self::isPersonal()))
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->setParameter('viewer', $viewer->getId(), 'uuid')
            ->setParameter('human', ($human ?? $viewer)->getId(), 'uuid')
            ->setParameter('published', IdeaVisibility::Published);

        $this->applyKind($qb, $filter);

        return $this->paginate($this->applyFilter($qb, $filter), $filter);
    }

    /**
     * Vue gestionnaire (spec §5.15): a managed profile's whole list —
     * the child's ideas (drafts included), friends' published
     * suggestions, and the manager's own drafts for the child. Never
     * a friend's private draft (règle 2).
     *
     * @return Paginator<Idea>
     */
    public function findManagerView(User $child, User $manager, IdeaListFilter $filter): Paginator
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere(self::hasRecipient('child'))
            ->andWhere('i.visibility = :published OR i.author = :child OR i.author = :manager')
            // A suggestion the manager is a co-recipient of stays hidden from them.
            ->andWhere(\sprintf('NOT (%s) OR %s', self::hasRecipient('manager'), self::isPersonal()))
            ->setParameter('child', $child->getId(), 'uuid')
            ->setParameter('manager', $manager->getId(), 'uuid')
            ->setParameter('published', IdeaVisibility::Published);

        $this->applyKind($qb, $filter);

        return $this->paginate($this->applyFilter($qb, $filter), $filter);
    }

    private function applyKind(QueryBuilder $qb, IdeaListFilter $filter): void
    {
        if ('personal' === $filter->kind) {
            $qb->andWhere(self::isPersonal('kind'));
        } elseif ('suggestion' === $filter->kind) {
            $qb->andWhere(\sprintf('NOT (%s)', self::isPersonal('kind')));
        }
    }

    /**
     * Every private idea I wrote, for myself or for friends (spec §5.4
     * "un écran « Privées » liste tous mes brouillons").
     *
     * @return Idea[]
     */
    public function findPrivateByAuthor(User $me): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.author = :me AND i.visibility = :private AND i.status = :active')
            ->setParameter('me', $me->getId(), 'uuid')
            ->setParameter('private', IdeaVisibility::Private)
            ->setParameter('active', IdeaStatus::Active)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Segment counts of "Ma liste" — own ideas only.
     *
     * @return array{published: int, drafts: int, archived: int}
     */
    public function countOwnerView(User $me): array
    {
        $rows = $this->ownerViewQuery($me)
            ->select('i.status AS status, i.visibility AS visibility, COUNT(i.id) AS total')
            ->groupBy('i.status, i.visibility')
            ->getQuery()
            ->getArrayResult();

        $counts = ['published' => 0, 'drafts' => 0, 'archived' => 0];
        foreach ($rows as $row) {
            // Scalar hydration may hand back the enum or its raw value.
            $status = $row['status'] instanceof IdeaStatus ? $row['status'] : IdeaStatus::from($row['status']);
            $visibility = $row['visibility'] instanceof IdeaVisibility ? $row['visibility'] : IdeaVisibility::from($row['visibility']);
            $key = match (true) {
                IdeaStatus::Archived === $status => 'archived',
                IdeaVisibility::Published === $visibility => 'published',
                default => 'drafts',
            };
            $counts[$key] += (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Published, active ideas per owner, as seen by `$viewer`, a friend
     * (personal ideas and suggestions alike; never anyone's drafts) —
     * the friends list's "N idées". A suggestion `$viewer` is a
     * co-recipient of is never counted (règle d'or, décision 69).
     *
     * @param string[] $ownerIds
     *
     * @return array<string, int>
     */
    public function countFriendVisibleByOwner(array $ownerIds, User $viewer): array
    {
        if ([] === $ownerIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(ro.user) AS ownerId, COUNT(i.id) AS total')
            ->innerJoin('App\\Entity\\IdeaRecipient', 'ro', 'WITH', 'ro.idea = i AND ro.deletedAt IS NULL')
            ->andWhere('ro.user IN (:owners)')
            ->andWhere('i.visibility = :published AND i.status = :active')
            ->andWhere(\sprintf('NOT (%s) OR %s', self::hasRecipient('viewer'), self::isPersonal()))
            ->setParameter('owners', $ownerIds)
            ->setParameter('viewer', $viewer->getId(), 'uuid')
            ->setParameter('published', IdeaVisibility::Published)
            ->setParameter('active', IdeaStatus::Active)
            ->groupBy('ro.user')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['ownerId']] = (int) $row['total'];
        }

        return $counts;
    }

    private function applyFilter(QueryBuilder $qb, IdeaListFilter $filter): QueryBuilder
    {
        $qb->andWhere('i.status = :status')->setParameter('status', $filter->status);

        if (null !== $filter->visibility) {
            $qb->andWhere('i.visibility = :visibility')->setParameter('visibility', $filter->visibility);
        }

        if (null !== $filter->occasion) {
            $qb->innerJoin('i.occasion', 'o')->andWhere('o.code = :occasion')->setParameter('occasion', $filter->occasion);
        }

        if (null !== $filter->search) {
            $needle = '%'.addcslashes(mb_strtolower($filter->search), '%_\\').'%';
            $qb->andWhere('LOWER(i.title) LIKE :search OR LOWER(i.note) LIKE :search')->setParameter('search', $needle);
        }

        match ($filter->sort) {
            'price_asc', 'price_desc' => $qb
                // Ideas without a price always come last, whichever the direction.
                ->addSelect('CASE WHEN i.priceAmount IS NULL THEN 1 ELSE 0 END AS HIDDEN priceMissing')
                ->orderBy('priceMissing', 'ASC')
                ->addOrderBy('i.priceAmount', 'price_asc' === $filter->sort ? 'ASC' : 'DESC')
                ->addOrderBy('i.createdAt', 'DESC'),
            default => $qb->orderBy('i.createdAt', 'DESC'),
        };
        $qb->addOrderBy('i.id', 'DESC');

        return $qb;
    }

    /**
     * @return Paginator<Idea>
     */
    private function paginate(QueryBuilder $qb, IdeaListFilter $filter): Paginator
    {
        $qb->setFirstResult(($filter->page - 1) * $filter->itemsPerPage)->setMaxResults($filter->itemsPerPage);

        return new Paginator($qb->getQuery(), fetchJoinCollection: false);
    }
}
