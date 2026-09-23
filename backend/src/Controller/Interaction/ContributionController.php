<?php

declare(strict_types=1);

namespace App\Controller\Interaction;

use App\Entity\Contribution;
use App\Entity\ContributionPledge;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Repository\ContributionPledgeRepository;
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
 * Cotisation à plusieurs (spec §5.10): declarative, no payment, hidden
 * from the owner. The initiator manages the contribution (target,
 * closing), each friend manages their own pledge. Individual amounts:
 * see InteractionNormalizer::contribution() (CLAUDE.md règle 3).
 */
final class ContributionController
{
    public function __construct(
        private readonly InteractionRequest $request,
        private readonly IdeaAccess $access,
        private readonly ContributionRepository $contributions,
        private readonly ContributionPledgeRepository $pledges,
        private readonly ReservationRepository $reservations,
        private readonly InteractionNormalizer $normalizer,
        private readonly IdeaNormalizer $ideaNormalizer,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/contributions', name: 'contributions_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $body = $this->request->body($request);
        $idea = $this->request->idea($body['ideaId'] ?? null);
        $this->access->assertCanInteract($idea, $me);

        $id = $this->request->clientId($body);
        if (null !== $id && null !== $existing = $this->request->findIncludingDeleted(Contribution::class, $id)) {
            if ($existing->getInitiator() !== $me || $existing->getIdea() !== $idea || $existing->isDeleted()) {
                throw new ApiProblemException('request.conflict', 'This id is already used.', 409);
            }

            return $this->respond($existing, $me);
        }

        $target = \array_key_exists('targetAmount', $body) ? ReservationController::parseTarget($body['targetAmount']) : $idea->getPriceAmount();
        $currency = isset($body['currency']) ? IdeaFieldsApplier::parseCurrency($body['currency']) : $idea->getPriceCurrency();

        try {
            $contribution = $this->em->wrapInTransaction(function () use ($idea, $me, $id, $target, $currency): Contribution {
                $this->em->lock($idea, LockMode::PESSIMISTIC_WRITE);

                $key = $idea->getId()->toRfc4122();
                if (isset($this->reservations->findActiveByIdeas([$key])[$key])) {
                    // Spec §5.10: the reserver converts their reservation instead.
                    throw new ApiProblemException('contribution.idea_reserved', 'This idea is reserved.', 409);
                }
                if (null !== $open = $this->contributions->findOpenForIdea($idea)) {
                    throw $this->alreadyOpen($open);
                }

                $contribution = new Contribution($idea, $me, $target, $currency, $id);
                $this->em->persist($contribution);
                $this->em->flush();

                return $contribution;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ApiProblemException('contribution.already_open', 'A contribution is already open on this idea.', 409);
        }

        return $this->respond($contribution, $me, 201);
    }

    /**
     * The contribution plus its idea (friend view), for the Cotisation
     * screen's header.
     */
    #[Route('/api/contributions/{id}', name: 'contributions_show', methods: ['GET'])]
    public function show(string $id, #[CurrentUser] User $me): JsonResponse
    {
        $contribution = $this->visible($id, $me);

        return $this->respond($contribution, $me);
    }

    #[Route('/api/contributions/{id}', name: 'contributions_update', methods: ['PATCH'])]
    public function update(string $id, Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $contribution = $this->managed($id, $me);
        $body = $this->request->body($request);

        if (\array_key_exists('targetAmount', $body)) {
            $contribution->setTargetAmount(ReservationController::parseTarget($body['targetAmount']));
        }
        $this->em->flush();

        return $this->respond($contribution, $me);
    }

    #[Route('/api/contributions/{id}/close', name: 'contributions_close', methods: ['POST'])]
    public function close(string $id, #[CurrentUser] User $me): JsonResponse
    {
        $contribution = $this->managed($id, $me);
        $contribution->close();
        $this->em->flush();

        return $this->respond($contribution, $me);
    }

    /**
     * Declares or updates my pledge (upsert: one per person). Refused
     * once the contribution is closed (spec §8, "participation refusée
     * avec message").
     */
    #[Route('/api/contributions/{id}/pledge', name: 'contributions_pledge_put', methods: ['PUT'])]
    public function pledge(string $id, Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $contribution = $this->visible($id, $me);
        $this->access->assertCanInteract($contribution->getIdea(), $me);
        $this->assertOpen($contribution);

        $amount = Money::parse($this->request->body($request)['amount'] ?? null);
        if (null === $amount || 0 === Money::toCents($amount)) {
            throw new ApiProblemException('validation.pledge_amount_invalid', 'The amount must be greater than zero.', 422);
        }

        $pledge = $this->pledges->findOneFor($contribution, $me);
        if (null === $pledge) {
            $this->em->persist(new ContributionPledge($contribution, $me, $amount));
        } else {
            $pledge->setAmount($amount);
        }
        $this->em->flush();

        return $this->respond($contribution, $me);
    }

    #[Route('/api/contributions/{id}/pledge', name: 'contributions_pledge_delete', methods: ['DELETE'])]
    public function withdraw(string $id, #[CurrentUser] User $me): JsonResponse
    {
        $contribution = $this->visible($id, $me);
        $this->assertOpen($contribution);

        $this->pledges->findOneFor($contribution, $me)?->markDeleted();
        $this->em->flush();

        return $this->respond($contribution, $me);
    }

    private function visible(string $id, User $me): Contribution
    {
        $contribution = $this->request->find(Contribution::class, $id);
        $this->access->assertCanSeeInteractions($contribution->getIdea(), $me);

        return $contribution;
    }

    /** Spec §4: "l'initiateur gère la cotisation". */
    private function managed(string $id, User $me): Contribution
    {
        $contribution = $this->visible($id, $me);
        if ($contribution->getInitiator() !== $me) {
            throw new ApiProblemException('contribution.not_initiator', 'Only the initiator manages this contribution.', 403);
        }
        $this->assertOpen($contribution);

        return $contribution;
    }

    private function assertOpen(Contribution $contribution): void
    {
        if (!$contribution->isOpen()) {
            throw new ApiProblemException('contribution.closed', 'This contribution is closed.', 422);
        }
    }

    private function alreadyOpen(Contribution $open): ApiProblemException
    {
        // Spec §5.10: the loser of the race can pledge to the existing one.
        return new ApiProblemException('contribution.already_open', 'A contribution is already open on this idea.', 409, extra: [
            'contributionId' => $open->getId()->toRfc4122(),
        ]);
    }

    private function respond(Contribution $contribution, User $me, int $status = 200): JsonResponse
    {
        $pledges = $this->pledges->findByContributions([$contribution->getId()->toRfc4122()])[$contribution->getId()->toRfc4122()] ?? [];

        return new JsonResponse(
            $this->normalizer->contribution($contribution, $pledges, $me)
            + ['idea' => $this->ideaNormalizer->normalizeForFriend($contribution->getIdea(), $me)],
            $status,
        );
    }
}
