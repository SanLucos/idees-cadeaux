<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\UserType;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * POST /api/contacts/match (spec §5.3): the device hashes its
 * contacts' normalized emails (SHA-256) and sends only the hashes —
 * never a raw contact, never stored server-side. Same normalization
 * as everywhere else in the API (lowercase, trimmed) so our users'
 * emails hash to the same values the client computed.
 */
final class ContactsMatchController
{
    private const int MAX_HASHES_PER_REQUEST = 1000;

    public function __construct(private readonly UserRepository $users)
    {
    }

    #[Route('/api/contacts/match', name: 'contacts_match', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $me): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        $hashes = \is_array($body) ? ($body['hashedEmails'] ?? null) : null;
        if (!\is_array($hashes) || [] === $hashes || \count($hashes) > self::MAX_HASHES_PER_REQUEST) {
            throw new ApiProblemException('validation.hashed_emails_invalid', \sprintf('hashedEmails must be a non-empty array of at most %d entries.', self::MAX_HASHES_PER_REQUEST), 422);
        }

        $wanted = array_flip(array_map('strval', $hashes));

        $matches = [];
        // Managed profiles (spec §5.15) are excluded from contact matching.
        foreach ($this->users->findBy(['type' => UserType::Regular]) as $candidate) {
            if ($candidate === $me || null === $candidate->getEmail()) {
                continue;
            }

            $hash = hash('sha256', mb_strtolower(trim($candidate->getEmail())));
            if (isset($wanted[$hash])) {
                $matches[] = [
                    'id' => $candidate->getId()->toRfc4122(),
                    'displayName' => $candidate->getDisplayName(),
                ];
            }
        }

        return new JsonResponse($matches);
    }
}
