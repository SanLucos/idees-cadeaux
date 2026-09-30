<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ProfileSize;
use App\Exception\ApiProblemException;
use App\Exception\HiddenResourceException;
use App\Security\ActingContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Removing a past value from a size's history (spec §11 décision 51):
 * the owner's alone — the manager, for a child profile, acting as it
 * like for every other write on its sizes. For anyone else the history
 * doesn't exist: 404, even for a friend who reads the size itself.
 */
final class ProfileSizeHistoryController
{
    public function __construct(
        private readonly ActingContext $acting,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/profile_sizes/{id}/history/{entryId}', name: 'profile_size_history_delete', methods: ['DELETE'], requirements: ['id' => Requirement::UUID, 'entryId' => Requirement::UUID])]
    public function delete(string $id, string $entryId): Response
    {
        $size = $this->em->find(ProfileSize::class, Uuid::fromString($id));
        if (null === $size || $size->getUser() !== $this->acting->actor()) {
            throw new HiddenResourceException();
        }

        // An entry already gone is a replayed request (spec §8): same answer.
        if (!$size->removeFromHistory(Uuid::fromString($entryId))) {
            throw new ApiProblemException('profile_size.history_current', 'The current value cannot be removed from the history.', 422);
        }
        $this->em->flush();

        return new Response(null, 204);
    }
}
