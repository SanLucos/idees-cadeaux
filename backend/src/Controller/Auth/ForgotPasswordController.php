<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\Enum\VerificationCodePurpose;
use App\Exception\ApiProblemException;
use App\Message\SendVerificationCodeEmail;
use App\Repository\UserRepository;
use App\Security\VerificationCodeManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/auth/forgot-password (spec §5.1). Always answers the same
 * way regardless of whether the account exists — same anti-enumeration
 * pattern used for friend requests by email (spec §5.3).
 */
final class ForgotPasswordController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly VerificationCodeManager $verificationCodes,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/api/auth/forgot-password', name: 'auth_forgot_password', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            throw new ApiProblemException('validation.invalid_body', 'Malformed JSON body.', 400);
        }

        $email = mb_strtolower(trim((string) ($body['email'] ?? '')));
        $user = '' !== $email ? $this->users->findOneBy(['email' => $email]) : null;

        if (null !== $user) {
            $code = $this->verificationCodes->issue($user, VerificationCodePurpose::ResetPassword);
            $this->bus->dispatch(new SendVerificationCodeEmail($user->getId()->toRfc4122(), VerificationCodePurpose::ResetPassword, $code));
        }

        return new JsonResponse(['status' => 'sent_if_applicable']);
    }
}
