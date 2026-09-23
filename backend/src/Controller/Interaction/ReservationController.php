<?php

declare(strict_types=1);

namespace App\Controller\Interaction;

use App\Entity\Contribution;
use App\Entity\Idea;
use App\Entity\Reservation;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Repository\ContributionRepository;
use App\Repository\ReservationRepository;
use App\Security\IdeaAccess;
use App\Serializer\IdeaNormalizer;
use App\Serializer\InteractionNormalizer;
use App\Service\IdeaFieldsApplier;
use App\Util\Money;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * "Je l'offre" (spec §5.7). One active reservation per idea, never
 * alongside an open contribution; first sync wins, the loser learns
 * who got there first. Everything 404s for the idea's owner.
 */
final class ReservationController
{
    public function __construct(
        private readonly InteractionRequest $request,
        private readonly IdeaAccess $access,
        private readonly ReservationRepository $reservations,
        private readonly ContributionRepository $contributions,
        private readonly IdeaNormalizer $ideaNormalizer,
        private readonly InteractionNormalizer $normalizer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Responds with the idea's friend view, reservation included.
     */
    #[Route('/api/reservations', name: 'reservations_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $body = $this->request->body($request);
        $idea = $this->request->idea($body['ideaId'] ?? null);
        $this->access->assertCanInteract($idea, $me);

        $id = $this->request->clientId($body);
        if (null !== $id && null !== $existing = $this->request->findIncludingDeleted(Reservation::class, $id)) {
            if ($existing->getUser() !== $me || $existing->getIdea() !== $idea || $existing->isDeleted()) {
                throw new ApiProblemException('request.conflict', 'This id is already used.', 409);
            }

            return new JsonResponse($this->ideaNormalizer->normalizeFor($idea, $me));
        }

        try {
            $created = $this->em->wrapInTransaction(function () use ($idea, $me, $id): bool {
                // Serialises reservation and contribution creation per idea.
                $this->em->lock($idea, LockMode::PESSIMISTIC_WRITE);
                $this->assertFree($idea, $me);
                if ($this->reservedBy($idea) === $me) {
                    return false;
                }

                $this->em->persist(new Reservation($idea, $me, $id));
                $this->em->flush();

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race the lock didn't cover: report the winner.
            $this->em->clear();
            throw $this->alreadyReserved($this->reservations->findActiveByIdeas([$idea->getId()->toRfc4122()])[$idea->getId()->toRfc4122()] ?? null);
        }

        return new JsonResponse($this->ideaNormalizer->normalizeFor($idea, $me), $created ? 201 : 200);
    }

    /** Spec §5.7: only the reserver cancels. */
    #[Route('/api/reservations/{id}', name: 'reservations_delete', methods: ['DELETE'])]
    public function delete(string $id, #[CurrentUser] User $me): JsonResponse
    {
        $reservation = $this->mine($id, $me);

        $reservation->markDeleted();
        $this->em->flush();

        return new JsonResponse($this->ideaNormalizer->normalizeFor($reservation->getIdea(), $me));
    }

    /**
     * Spec §5.7 "ouvrir à plusieurs": the reservation becomes a
     * contribution the reserver initiates (target: the idea's price by
     * default). The client then offers to declare their own pledge.
     */
    #[Route('/api/reservations/{id}/convert-to-contribution', name: 'reservations_convert', methods: ['POST'])]
    public function convert(string $id, Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $reservation = $this->mine($id, $me);
        $idea = $reservation->getIdea();
        $this->access->assertCanInteract($idea, $me);

        $body = $this->request->body($request);
        $target = \array_key_exists('targetAmount', $body) ? self::parseTarget($body['targetAmount']) : $idea->getPriceAmount();
        $currency = isset($body['currency']) ? IdeaFieldsApplier::parseCurrency($body['currency']) : $idea->getPriceCurrency();

        $contribution = new Contribution($idea, $me, $target, $currency, $this->request->clientId($body));
        $reservation->markDeleted();
        $this->em->persist($contribution);
        $this->em->flush();

        // Same shape as ContributionController's responses.
        return new JsonResponse(
            $this->normalizer->contribution($contribution, [], $me) + ['idea' => $this->ideaNormalizer->normalizeFor($idea, $me)],
            201,
        );
    }

    public static function parseTarget(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }
        $amount = Money::parse($value);
        if (null === $amount || 0 === Money::toCents($amount)) {
            throw new ApiProblemException('validation.contribution_target_invalid', 'The target must be a positive amount.', 422);
        }

        return $amount;
    }

    private function mine(string $id, User $me): Reservation
    {
        $reservation = $this->request->find(Reservation::class, $id);
        $this->access->assertCanSeeInteractions($reservation->getIdea(), $me);

        if ($reservation->getUser() !== $me) {
            throw new ApiProblemException('reservation.not_yours', 'Only whoever reserved can do this.', 403);
        }

        return $reservation;
    }

    private function reservedBy(Idea $idea): ?User
    {
        $key = $idea->getId()->toRfc4122();

        return ($this->reservations->findActiveByIdeas([$key])[$key] ?? null)?->getUser();
    }

    private function assertFree(Idea $idea, User $me): void
    {
        $key = $idea->getId()->toRfc4122();
        $reservation = $this->reservations->findActiveByIdeas([$key])[$key] ?? null;
        if (null !== $reservation && $reservation->getUser() !== $me) {
            throw $this->alreadyReserved($reservation);
        }

        if (null !== $open = $this->contributions->findOpenForIdea($idea)) {
            throw new ApiProblemException('reservation.contribution_open', 'A contribution is already open on this idea.', 409, extra: [
                'contributionId' => $open->getId()->toRfc4122(),
            ]);
        }
    }

    private function alreadyReserved(?Reservation $winner): ApiProblemException
    {
        return new ApiProblemException('reservation.already_reserved', 'This idea is already reserved.', 409, extra: [
            'reservedBy' => $winner?->getUser()->getDisplayName(),
        ]);
    }
}
