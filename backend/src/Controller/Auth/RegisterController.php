<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Entity\Enum\UserType;
use App\Entity\Enum\VerificationCodePurpose;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Message\SendVerificationCodeEmail;
use App\Repository\UserRepository;
use App\Security\VerificationCodeManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * POST /api/auth/register (spec §5.1): email + password only, the
 * account starts with no pseudo — onboarding (client-side, via
 * PATCH /api/users/me) fills it in afterwards.
 */
final class RegisterController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly VerificationCodeManager $verificationCodes,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/api/auth/register', name: 'auth_register', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            throw new ApiProblemException('validation.invalid_body', 'Malformed JSON body.', 400);
        }

        $email = self::normalizeEmail((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $locale = (string) ($body['locale'] ?? 'fr');

        if ('' === $email || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new ApiProblemException('validation.email_invalid', 'A valid email is required.', 422);
        }
        if (\strlen($password) < 10) {
            throw new ApiProblemException('validation.password_too_short', 'Password must be at least 10 characters.', 422);
        }
        if (null !== $this->users->findOneBy(['email' => $email])) {
            throw new ApiProblemException('auth.email_already_registered', 'This email is already registered.', 409);
        }

        $clientId = isset($body['id']) && Uuid::isValid((string) $body['id']) ? Uuid::fromString((string) $body['id']) : null;

        $user = new User(UserType::Regular, displayName: null, locale: \in_array($locale, ['fr', 'en'], true) ? $locale : 'fr', id: $clientId);
        $user->setEmail($email);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));

        $this->em->persist($user);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw new ApiProblemException('auth.email_already_registered', 'This email is already registered.', 409);
        }

        $code = $this->verificationCodes->issue($user, VerificationCodePurpose::VerifyEmail);
        $this->bus->dispatch(new SendVerificationCodeEmail($user->getId()->toRfc4122(), VerificationCodePurpose::VerifyEmail, $code));

        return new JsonResponse(['id' => $user->getId()->toRfc4122(), 'email' => $email], 201);
    }

    private static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
