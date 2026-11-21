<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Exception\HiddenResourceException;
use App\Repository\FriendshipRepository;
use App\Repository\IntroductionRepository;
use App\Repository\UserRepository;
use App\Security\AdminGuard;
use App\Service\AdminFriendships;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * The administrator introduces people, makes them friends silently, or
 * ends a friendship silently (spec §5.17, §11 décision 71).
 */
final class AdminFriendshipsController
{
    public const int MAX_USERS = 20;

    public function __construct(
        private readonly AdminGuard $guard,
        private readonly AdminFriendships $admin,
        private readonly UserRepository $users,
        private readonly FriendshipRepository $friendships,
        private readonly IntroductionRepository $introductions,
    ) {
    }

    /**
     * Body: `{users: [<id>, …], mode: "introduce"|"force"}`, every pair
     * among 2 to 20 users. Answers how many pairs were done and how many
     * were unavailable (never why).
     */
    #[Route('/api/admin/connections', name: 'admin_connect', methods: ['POST'])]
    public function connect(Request $request, #[CurrentUser] ?User $me): JsonResponse
    {
        $admin = $this->guard->check($me);

        $body = json_decode($request->getContent() ?: '{}', true);
        $mode = \is_array($body) ? ($body['mode'] ?? null) : null;
        if (!\in_array($mode, [AdminFriendships::MODE_INTRODUCE, AdminFriendships::MODE_FORCE], true)) {
            throw new ApiProblemException('admin.mode_invalid', 'Mode must be "introduce" or "force".', 422);
        }
        $ids = $body['users'] ?? null;
        $ids = \is_array($ids) ? array_values(array_unique(array_map('strval', $ids))) : [];
        if (\count($ids) < 2 || \count($ids) > self::MAX_USERS) {
            throw new ApiProblemException('admin.users_invalid', 'Choose between 2 and 20 users.', 422);
        }
        $users = [];
        foreach ($ids as $id) {
            $users[] = (Uuid::isValid($id) ? $this->users->find($id) : null)
                ?? throw new ApiProblemException('admin.users_invalid', 'Unknown user.', 422);
        }

        $done = 0;
        $unavailable = 0;
        for ($i = 0; $i < \count($users); ++$i) {
            for ($j = $i + 1; $j < \count($users); ++$j) {
                $this->admin->connect($admin, $users[$i], $users[$j], $mode) ? ++$done : ++$unavailable;
            }
        }

        return new JsonResponse(['done' => $done, 'unavailable' => $unavailable]);
    }

    #[Route('/api/admin/friendships/{id}', name: 'admin_friendship_remove', methods: ['DELETE'])]
    public function remove(string $id, #[CurrentUser] ?User $me): JsonResponse
    {
        $this->guard->check($me);
        $friendship = Uuid::isValid($id) ? $this->friendships->find($id) : null;
        if (null === $friendship || !$this->admin->remove($friendship)) {
            throw new HiddenResourceException();
        }

        return new JsonResponse(null, 204);
    }

    #[Route('/api/admin/introductions/{id}', name: 'admin_introduction_cancel', methods: ['DELETE'])]
    public function cancel(string $id, #[CurrentUser] ?User $me): JsonResponse
    {
        $this->guard->check($me);
        $introduction = Uuid::isValid($id) ? $this->introductions->find($id) : null;
        if (null === $introduction || !$this->admin->cancel($introduction)) {
            throw new HiddenResourceException();
        }

        return new JsonResponse(null, 204);
    }
}
