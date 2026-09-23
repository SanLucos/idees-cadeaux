<?php

declare(strict_types=1);

namespace App\Controller\Notification;

use App\Entity\Notification;
use App\Entity\User;
use App\Exception\HiddenResourceException;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The in-app centre (spec §5.11): the adult's own notifications,
 * children's ones included (« Pour Jules »). Never while acting as a
 * child (ActingAsGuardListener): a child has no centre of its own.
 */
final class NotificationController
{
    private const int PER_PAGE = 30;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/notifications', name: 'notifications_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $paginator = $this->notifications->pageFor($me, $page, self::PER_PAGE);

        return new JsonResponse([
            'member' => array_map(self::normalize(...), iterator_to_array($paginator)),
            'totalItems' => \count($paginator),
            'unreadCount' => $this->notifications->countUnread($me),
            'page' => $page,
        ]);
    }

    /** For the tab badge. */
    #[Route('/api/notifications/unread-count', name: 'notifications_unread', methods: ['GET'], priority: 10)]
    public function unreadCount(#[CurrentUser] User $me): JsonResponse
    {
        return new JsonResponse(['unreadCount' => $this->notifications->countUnread($me)]);
    }

    #[Route('/api/notifications/{id}/read', name: 'notifications_read', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function read(string $id, #[CurrentUser] User $me): JsonResponse
    {
        $notification = $this->notifications->find($id);
        if (null === $notification || $notification->getUser() !== $me) {
            throw new HiddenResourceException();
        }
        $notification->markRead();
        $this->em->flush();

        return new JsonResponse(self::normalize($notification));
    }

    #[Route('/api/notifications/read-all', name: 'notifications_read_all', methods: ['POST'], priority: 10)]
    public function readAll(#[CurrentUser] User $me): JsonResponse
    {
        $this->notifications->markAllRead($me);

        return new JsonResponse(['unreadCount' => 0]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalize(Notification $notification): array
    {
        return [
            'id' => $notification->getId()->toRfc4122(),
            'type' => $notification->getType()->value,
            'payload' => $notification->getPayload(),
            'readAt' => $notification->getReadAt()?->format(\DATE_ATOM),
            'createdAt' => $notification->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
