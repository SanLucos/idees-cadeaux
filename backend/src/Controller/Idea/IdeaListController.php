<?php

declare(strict_types=1);

namespace App\Controller\Idea;

use App\Dto\IdeaListFilter;
use App\Entity\Idea;
use App\Entity\User;
use App\Exception\HiddenResourceException;
use App\Repository\IdeaRepository;
use App\Repository\UserRepository;
use App\Security\IdeaAccess;
use App\Serializer\IdeaNormalizer;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use App\Security\Attribute\ActingUser;

/**
 * Idea lists (spec §5.4 "Consultation", §7 `GET /users/{id}/ideas`).
 * Filters: see App\Dto\IdeaListFilter. Response mirrors Hydra's
 * `member` / `totalItems`, paginated by 20 (spec §7).
 */
final class IdeaListController
{
    public function __construct(
        private readonly IdeaRepository $ideas,
        private readonly UserRepository $users,
        private readonly IdeaAccess $access,
        private readonly IdeaNormalizer $normalizer,
    ) {
    }

    /**
     * Vue propriétaire of my own list, with the segment counts of
     * "Ma liste" (Publiées / Brouillons / Archives).
     */
    #[Route('/api/users/me/ideas', name: 'ideas_mine', methods: ['GET'], priority: 10)]
    public function mine(Request $request, #[ActingUser] User $me): JsonResponse
    {
        $filter = IdeaListFilter::fromRequest($request);

        if ($me->isManaged()) {
            // Acting as a child: its manager reads its list in full (spec §5.15).
            return $this->managerView($me, $me, $filter);
        }

        return new JsonResponse(
            $this->page($this->ideas->findOwnerView($me, $filter), $filter, fn (array $ideas) => array_map($this->normalizer->normalizeForOwner(...), $ideas))
            + ['counts' => $this->ideas->countOwnerView($me)],
        );
    }

    /**
     * A friend's list (vue ami). My own id gives my owner view; anyone
     * who isn't me or a friend gets the same 404 as an unknown id.
     */
    #[Route('/api/users/{id}/ideas', name: 'ideas_of_user', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function ofUser(string $id, Request $request, #[ActingUser] User $me): JsonResponse
    {
        $owner = $this->users->find($id);
        $view = null !== $owner ? $this->access->listViewFor($owner, $me) : null;

        if ('owner' === $view) {
            return $this->mine($request, $me);
        }
        if ('manager' === $view) {
            return $this->managerView($owner, $me, IdeaListFilter::fromRequest($request));
        }
        if ('friend' !== $view) {
            throw new HiddenResourceException();
        }

        $filter = IdeaListFilter::fromRequest($request);

        return new JsonResponse(
            $this->page($this->ideas->findFriendView($owner, $me, $filter), $filter, fn (array $ideas) => $this->normalizer->normalizeManyForFriend($ideas, $me)),
        );
    }

    /**
     * Every private idea I wrote (the « Privées » screen), each with its
     * recipient so the client can group them.
     */
    #[Route('/api/ideas/private', name: 'ideas_private', methods: ['GET'], priority: 10)]
    public function private(#[ActingUser] User $me): JsonResponse
    {
        return new JsonResponse(array_map(function (Idea $idea) use ($me): array {
            $owner = $idea->getOwner();
            $data = $this->normalizer->normalizeFor($idea, $me);

            return $data + ['recipient' => ['id' => $owner->getId()->toRfc4122(), 'displayName' => $owner->getDisplayName()]];
        }, $this->ideas->findPrivateByAuthor($me)));
    }

    private function managerView(User $child, User $viewer, IdeaListFilter $filter): JsonResponse
    {
        return new JsonResponse(
            $this->page(
                $this->ideas->findManagerView($child, IdeaAccess::humanBehind($viewer), $filter),
                $filter,
                fn (array $ideas) => $this->normalizer->normalizeManyForManager($ideas, $viewer),
            ) + ['counts' => $this->ideas->countOwnerView($child)],
        );
    }

    /**
     * @param Paginator<Idea>                                      $paginator
     * @param callable(Idea[]): list<array<string, mixed>> $normalize
     *
     * @return array<string, mixed>
     */
    private function page(Paginator $paginator, IdeaListFilter $filter, callable $normalize): array
    {
        return [
            'member' => array_values($normalize(iterator_to_array($paginator))),
            'totalItems' => \count($paginator),
            'page' => $filter->page,
            'itemsPerPage' => $filter->itemsPerPage,
        ];
    }
}
