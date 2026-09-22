<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\Enum\VerificationCodePurpose;
use App\Exception\ApiProblemException;
use App\Repository\UserRepository;
use App\Security\VerificationCodeManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class VerifyEmailController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly VerificationCodeManager $verificationCodes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/auth/verify-email', name: 'auth_verify_email', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            throw new ApiProblemException('validation.invalid_body', 'Malformed JSON body.', 400);
        }

        $email = mb_strtolower(trim((string) ($body['email'] ?? '')));
        $code = (string) ($body['code'] ?? '');

        $user = '' !== $email ? $this->users->findOneBy(['email' => $email]) : null;
        if (null === $user) {
            throw new ApiProblemException('auth.invalid_code', 'Invalid or expired code.', 422);
        }

        if (null !== $user->getEmailVerifiedAt()) {
            return new JsonResponse(['status' => 'already_verified']);
        }

        if (!$this->verificationCodes->verify($user, VerificationCodePurpose::VerifyEmail, $code)) {
            throw new ApiProblemException('auth.invalid_code', 'Invalid or expired code.', 422);
        }

        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse(['status' => 'verified']);
    }
}
