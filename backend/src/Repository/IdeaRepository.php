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
 * List queries apply the same rules as App\Security\IdeaAccess::canView,
 * in SQL. Keep the two in step: tests/Visibility/IdeaVisibilityTest
 * checks both paths.
 *
 * @extends ServiceEntityRepository<Idea>
 */
class IdeaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Idea::class);
    }

    /**
     * Vue propriétaire: my own ideas only (author = owner = me). A
     * suggestion on my list never appears here, whatever its state.
     *
     * @return Paginator<Idea>
     */
    public function findOwnerView(User $me, IdeaListFilter $filter): Paginator
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.owner = :me AND i.author = :me')
            ->setParameter('me', $me->getId(), 'uuid');

        return $this->paginate($this->applyFilter($qb, $filter), $filter);
    }

    /**
     * Vue ami: `$owner`'s personal ideas and their friends' suggestions,
     * published — plus my own drafts for them (visible to me alone).
     * The caller has already checked `$viewer` is `$owner`'s friend.
     *
     * @return Paginator<Idea>
     */
    public function findFriendView(User $owner, User $viewer, IdeaListFilter $filter): Paginator
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.owner = :owner')
            ->andWhere('i.visibility = :published OR i.author = :viewer')
            ->setParameter('owner', $owner->getId(), 'uuid')
            ->setParameter('viewer', $viewer->getId(), 'uuid')
            ->setParameter('published', IdeaVisibility::Published);

        if ('personal' === $filter->kind) {
            $qb->andWhere('i.author = i.owner');
        } elseif ('suggestion' === $filter->kind) {
            $qb->andWhere('i.author != i.owner');
        }

        return $this->paginate($this->applyFilter($qb, $filter), $filter);
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
        $rows = $this->createQueryBuilder('i')
            ->select('i.status AS status, i.visibility AS visibility, COUNT(i.id) AS total')
            ->andWhere('i.owner = :me AND i.author = :me')
            ->setParameter('me', $me->getId(), 'uuid')
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
     * Published, active ideas per owner, as seen by a friend (personal
     * ideas and suggestions alike; never anyone's drafts) — the friends
     * list's "N idées".
     *
     * @param string[] $ownerIds
     *
     * @return array<string, int>
     */
    public function countFriendVisibleByOwner(array $ownerIds): array
    {
        if ([] === $ownerIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.owner) AS ownerId, COUNT(i.id) AS total')
            ->andWhere('i.owner IN (:owners)')
            ->andWhere('i.visibility = :published AND i.status = :active')
            ->setParameter('owners', $ownerIds)
            ->setParameter('published', IdeaVisibility::Published)
            ->setParameter('active', IdeaStatus::Active)
            ->groupBy('i.owner')
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
