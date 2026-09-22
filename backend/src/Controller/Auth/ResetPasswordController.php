<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\Enum\VerificationCodePurpose;
use App\Exception\ApiProblemException;
use App\Repository\UserRepository;
use App\Security\VerificationCodeManager;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RevokeRefreshTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ResetPasswordController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly VerificationCodeManager $verificationCodes,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RevokeRefreshTokenManagerInterface $refreshTokens,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/auth/reset-password', name: 'auth_reset_password', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            throw new ApiProblemException('validation.invalid_body', 'Malformed JSON body.', 400);
        }

        $email = mb_strtolower(trim((string) ($body['email'] ?? '')));
        $code = (string) ($body['code'] ?? '');
        $newPassword = (string) ($body['newPassword'] ?? '');

        if (\strlen($newPassword) < 10) {
            throw new ApiProblemException('validation.password_too_short', 'Password must be at least 10 characters.', 422);
        }

        $user = '' !== $email ? $this->users->findOneBy(['email' => $email]) : null;
        if (null === $user || !$this->verificationCodes->verify($user, VerificationCodePurpose::ResetPassword, $code)) {
            throw new ApiProblemException('auth.invalid_code', 'Invalid or expired code.', 422);
        }

        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $newPassword));
        $this->em->flush();

        // Every device is signed out; the one that just reset the password logs in again fresh.
        $this->refreshTokens->revokeAllForUser($user);

        return new JsonResponse(['status' => 'reset']);
    }
}
