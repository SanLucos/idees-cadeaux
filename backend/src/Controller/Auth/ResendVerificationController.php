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

final class ResendVerificationController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly VerificationCodeManager $verificationCodes,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/api/auth/verify-email/resend', name: 'auth_verify_email_resend', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            throw new ApiProblemException('validation.invalid_body', 'Malformed JSON body.', 400);
        }

        $email = mb_strtolower(trim((string) ($body['email'] ?? '')));
        $user = '' !== $email ? $this->users->findOneBy(['email' => $email]) : null;

        // Same response whether the account exists or is already verified:
        // mirrors the anti-enumeration pattern from friend requests (spec §5.3).
        if (null !== $user && null === $user->getEmailVerifiedAt()) {
            $code = $this->verificationCodes->issue($user, VerificationCodePurpose::VerifyEmail);
            $this->bus->dispatch(new SendVerificationCodeEmail($user->getId()->toRfc4122(), VerificationCodePurpose::VerifyEmail, $code));
        }

        return new JsonResponse(['status' => 'sent_if_applicable']);
    }
}
