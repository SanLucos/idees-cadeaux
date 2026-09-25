<?php

declare(strict_types=1);

namespace App\Controller\Account;

use App\Entity\User;
use App\Security\Reauthentication;
use App\Serializer\UserNormalizer;
use App\Service\AccountDeletion;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * My account (spec §5.13), always as the signed-in adult — never on a
 * child's behalf (ActingAsGuardListener's default deny):
 *
 * - POST /api/account/reauth-code: a code by email, for accounts
 *   without a password;
 * - POST /api/account/deletion: `{password}` or `{code}`, starts the
 *   14 days of grace;
 * - POST /api/account/cancel-deletion.
 */
final class AccountController
{
    public function __construct(
        private readonly Reauthentication $reauthentication,
        private readonly AccountDeletion $deletion,
        private readonly UserNormalizer $normalizer,
    ) {
    }

    #[Route('/api/account/reauth-code', name: 'account_reauth_code', methods: ['POST'])]
    public function reauthCode(#[CurrentUser] User $me): JsonResponse
    {
        $this->reauthentication->sendCode($me);

        return new JsonResponse(['status' => 'sent'], 202);
    }

    #[Route('/api/account/deletion', name: 'account_deletion', methods: ['POST'])]
    public function requestDeletion(Request $request, #[CurrentUser] User $me): JsonResponse
    {
        if (null === $me->getDeletionScheduledAt()) {
            $this->reauthentication->check($me, self::body($request));
            $this->deletion->schedule($me);
        }

        return new JsonResponse($this->normalizer->normalize($me));
    }

    #[Route('/api/account/cancel-deletion', name: 'account_cancel_deletion_api', methods: ['POST'])]
    public function cancelDeletion(#[CurrentUser] User $me): JsonResponse
    {
        $this->deletion->cancel($me);

        return new JsonResponse($this->normalizer->normalize($me));
    }

    /**
     * @return array<string, mixed>
     */
    public static function body(Request $request): array
    {
        $body = json_decode($request->getContent(), true);

        return \is_array($body) ? $body : [];
    }
}
