<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Enum\FriendshipStatus;
use App\Entity\Enum\UserType;
use App\Entity\Friendship;
use App\Entity\Introduction;
use App\Entity\RefreshToken;
use App\Entity\SocialIdentity;
use App\Entity\User;
use App\Exception\HiddenResourceException;
use App\Repository\FriendshipRepository;
use App\Repository\IntroductionRepository;
use App\Repository\UserRepository;
use App\Security\AdminGuard;
use App\Service\AdminFriendships;
use App\Service\Introductions;
use App\Service\MediaUrls;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * The administration's accounts (spec §5.17, §11 décision 71). Account
 * metadata and the friendship graph only: never an idea, its count, an
 * interaction, a size or a preference. A refusal never shows as such.
 */
final class AdminUsersController
{
    public const int PAGE_SIZE = 50;

    public function __construct(
        private readonly AdminGuard $guard,
        private readonly UserRepository $users,
        private readonly FriendshipRepository $friendships,
        private readonly IntroductionRepository $introductions,
        private readonly Introductions $introductionRules,
        private readonly AdminFriendships $admin,
        private readonly MediaUrls $media,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * `?q=` (pseudo or email), `type=regular|managed|deleting`,
     * `sort=createdAt|displayName`, `page=`; with `relativeTo=<id>`,
     * each row tells where the pair stands (`relation`).
     */
    #[Route('/api/admin/users', name: 'admin_user_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] ?User $me): JsonResponse
    {
        $this->guard->check($me);

        $qb = $this->users->createQueryBuilder('u');
        $q = mb_strtolower(trim((string) $request->query->get('q', '')));
        if ('' !== $q) {
            $qb->andWhere('LOWER(u.displayName) LIKE :q OR LOWER(u.email) LIKE :q')
                ->setParameter('q', '%'.addcslashes($q, '%_\\').'%');
        }
        match ($request->query->get('type')) {
            'regular' => $qb->andWhere('u.type = :type')->setParameter('type', UserType::Regular),
            'managed' => $qb->andWhere('u.type = :type')->setParameter('type', UserType::Managed),
            'deleting' => $qb->andWhere('u.deletionScheduledAt IS NOT NULL'),
            default => null,
        };
        $total = (int) (clone $qb)->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();

        'displayName' === $request->query->get('sort')
            ? $qb->orderBy('LOWER(u.displayName)', 'ASC')->addOrderBy('u.createdAt', 'DESC')
            : $qb->orderBy('u.createdAt', 'DESC');
        $page = max(1, $request->query->getInt('page', 1));
        /** @var User[] $rows */
        $rows = $qb->setFirstResult(($page - 1) * self::PAGE_SIZE)->setMaxResults(self::PAGE_SIZE)->getQuery()->getResult();

        $relativeTo = $this->optionalUser($request->query->get('relativeTo'));

        return new JsonResponse([
            'total' => $total,
            'page' => $page,
            'pageSize' => self::PAGE_SIZE,
            'items' => $this->rows($rows, $relativeTo),
        ]);
    }

    #[Route('/api/admin/users/{id}', name: 'admin_user_show', methods: ['GET'])]
    public function show(string $id, #[CurrentUser] ?User $me): JsonResponse
    {
        $this->guard->check($me);
        $user = $this->optionalUser($id) ?? throw new HiddenResourceException();

        $person = fn (User $u) => ['id' => $u->getId()->toRfc4122(), 'displayName' => $u->getDisplayName(), 'avatarUrl' => $this->media->url($u->getAvatarPath()), 'type' => $u->getType()->value];

        // Pending only: a declined request is never shown (règle 4).
        $requests = $this->friendships->createQueryBuilder('f')
            ->andWhere('f.requester = :u OR f.addressee = :u')
            ->andWhere('f.status = :pending')
            ->setParameter('u', $user->getId(), 'uuid')
            ->setParameter('pending', FriendshipStatus::Pending)
            ->orderBy('f.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return new JsonResponse([
            ...$this->rows([$user])[0],
            'children' => array_map($person, $user->isManaged() ? [] : $this->users->findChildrenOf($user)),
            'friends' => array_map(fn (Friendship $f) => [
                'friendshipId' => $f->getId()->toRfc4122(),
                'user' => $person($f->otherParty($user)),
                // The stored origin: the administration alone sees `admin`.
                'origin' => $f->getOrigin()->value,
                'since' => $f->getRespondedAt()?->format(\DATE_ATOM),
            ], $this->friendships->findAccepted($user)),
            'requests' => array_map(fn (Friendship $f) => [
                'direction' => $f->getRequester() === $user ? 'outgoing' : 'incoming',
                'user' => $person($f->otherParty($user)),
                'createdAt' => $f->getCreatedAt()->format(\DATE_ATOM),
            ], $requests),
            'introductions' => array_map(fn (Introduction $i) => [
                'id' => $i->getId()->toRfc4122(),
                'user' => $person($i->otherThan($user)),
                'createdAt' => $i->getCreatedAt()->format(\DATE_ATOM),
                'expiresAt' => $i->getExpiresAt()->format(\DATE_ATOM),
            ], array_values(array_filter($this->introductions->findOpenInvolving($user), $this->introductionRules->isLive(...)))),
        ]);
    }

    /**
     * @param User[] $users
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $users, ?User $relativeTo = null): array
    {
        $ids = array_map(static fn (User $u) => $u->getId()->toRfc4122(), $users);
        $friendCounts = $this->friendships->countAcceptedFor($ids);
        $signIn = $this->signInMethods($users);

        return array_map(fn (User $u) => [
            'id' => $u->getId()->toRfc4122(),
            'displayName' => $u->getDisplayName(),
            'avatarUrl' => $this->media->url($u->getAvatarPath()),
            'email' => $u->getEmail(),
            'type' => $u->getType()->value,
            'managers' => array_map(static fn (User $m) => ['id' => $m->getId()->toRfc4122(), 'displayName' => $m->getDisplayName()], $u->getManagers()),
            'emailVerified' => null !== $u->getEmailVerifiedAt(),
            'deletionScheduledAt' => $u->getDeletionScheduledAt()?->format(\DATE_ATOM),
            'isSuperAdmin' => $u->isSuperAdmin(),
            'friendsCount' => $friendCounts[$u->getId()->toRfc4122()] ?? 0,
            'createdAt' => $u->getCreatedAt()->format(\DATE_ATOM),
            'signIn' => $signIn[$u->getId()->toRfc4122()],
            'relation' => null !== $relativeTo ? $this->admin->pairStatus($relativeTo, $u) : null,
        ], $users);
    }

    /**
     * How each account signs in: a password, an emailed link with its
     * long session still running (spec §11 décision 68), Google, Apple.
     *
     * @param User[] $users
     *
     * @return array<string, array{password: bool, emailLink: bool, social: list<string>}>
     */
    private function signInMethods(array $users): array
    {
        $byEmail = [];
        $methods = [];
        foreach ($users as $u) {
            $id = $u->getId()->toRfc4122();
            $methods[$id] = ['password' => null !== $u->getPasswordHash(), 'emailLink' => false, 'social' => []];
            if (null !== $u->getEmail()) {
                $byEmail[$u->getEmail()] = $id;
            }
        }

        if ([] !== $byEmail) {
            $longSessions = $this->em->getRepository(RefreshToken::class)->createQueryBuilder('t')
                ->select('DISTINCT t.username')
                ->andWhere('t.username IN (:emails) AND t.longSession = true AND t.valid > :now')
                ->setParameter('emails', array_keys($byEmail))
                ->setParameter('now', new \DateTimeImmutable())
                ->getQuery()
                ->getSingleColumnResult();
            foreach ($longSessions as $email) {
                $methods[$byEmail[$email]]['emailLink'] = true;
            }
        }

        if ([] !== $users) {
            /** @var SocialIdentity[] $identities */
            $identities = $this->em->getRepository(SocialIdentity::class)->findBy(['user' => $users]);
            foreach ($identities as $identity) {
                $methods[$identity->getUser()->getId()->toRfc4122()]['social'][] = $identity->getProvider()->value;
            }
        }

        return $methods;
    }

    private function optionalUser(mixed $id): ?User
    {
        return \is_string($id) && Uuid::isValid($id) ? $this->users->find($id) : null;
    }
}
