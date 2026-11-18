<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\EmailSignInCode;
use App\Entity\Enum\UserType;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Message\SendSignInEmail;
use App\Repository\EmailSignInCodeRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * Sign-in without a password (spec §11 décision 68, issue #8): an email
 * carrying a 6-digit code and a link holding the same code. Works for
 * every adult account, with or without a password, and creates the
 * account on the first sign-in — the email is verified by the same
 * stroke. Nothing is created before the code is used, so asking for a
 * code on someone else's address leaves no trace.
 */
final class EmailSignIn
{
    private const int TTL_SECONDS = 900;
    private const int MAX_ATTEMPTS = 5;
    private const array LOCALES = ['fr', 'en'];

    public function __construct(
        private readonly EmailSignInCodeRepository $codes,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly string $secret,
        #[Autowire(service: 'limiter.email_sign_in_request')]
        private readonly RateLimiterFactory $requestLimiter,
        #[Autowire(service: 'limiter.email_sign_in')]
        private readonly RateLimiterFactory $verifyLimiter,
    ) {
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Same answer whether or not an account exists (anti-enumeration,
     * as for a forgotten password).
     */
    public function send(string $email, string $locale): void
    {
        $email = self::normalizeEmail($email);
        if ('' === $email || !filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new ApiProblemException('validation.email_invalid', 'A valid email is required.', 422);
        }
        if (!$this->requestLimiter->create($email)->consume()->isAccepted()) {
            throw new ApiProblemException('auth.too_many_attempts', 'Too many attempts, try again later.', 429);
        }

        $user = $this->users->findOneBy(['email' => $email]);
        if (null !== $user && UserType::Regular !== $user->getType()) {
            return;
        }

        foreach ($this->codes->findForEmail($email) as $previous) {
            $this->em->remove($previous);
        }
        $code = str_pad((string) random_int(0, 999_999), 6, '0', \STR_PAD_LEFT);
        $this->em->persist(new EmailSignInCode($email, $this->hash($code), new \DateTimeImmutable('+'.self::TTL_SECONDS.' seconds')));
        $this->em->flush();

        $this->bus->dispatch(new SendSignInEmail(
            $email,
            $user?->getLocale() ?? (\in_array($locale, self::LOCALES, true) ? $locale : 'fr'),
            $code,
            null === $user,
        ));
    }

    /**
     * Checks the code, then returns the account — created here on a
     * first sign-in, with the client's UUID when it sent one.
     */
    public function signIn(string $email, string $code, string $locale, ?string $clientId): User
    {
        $email = self::normalizeEmail($email);
        if ('' === $email || !$this->verifyLimiter->create($email)->consume()->isAccepted()) {
            throw new ApiProblemException('auth.too_many_attempts', 'Too many attempts, try again later.', 429);
        }
        if (!$this->verify($email, trim($code))) {
            throw new ApiProblemException('auth.invalid_code', 'Invalid or expired code.', 422);
        }

        $user = $this->users->findOneBy(['email' => $email]);
        if (null !== $user && UserType::Regular !== $user->getType()) {
            throw new ApiProblemException('auth.invalid_code', 'Invalid or expired code.', 422);
        }

        if (null === $user) {
            $user = new User(
                UserType::Regular,
                displayName: null,
                locale: \in_array($locale, self::LOCALES, true) ? $locale : 'fr',
                id: null !== $clientId && Uuid::isValid($clientId) ? Uuid::fromString($clientId) : null,
            );
            $user->setEmail($email);
            $this->em->persist($user);
        }
        // Receiving the code proves the address (spec §5.1).
        $user->setEmailVerifiedAt($user->getEmailVerifiedAt() ?? new \DateTimeImmutable());

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Two sign-ins racing to create the same account.
            throw new ApiProblemException('auth.invalid_code', 'Invalid or expired code.', 422);
        }

        return $user;
    }

    private function verify(string $email, string $code): bool
    {
        $submitted = $this->hash($code);
        $valid = false;

        foreach ($this->codes->findForEmail($email) as $candidate) {
            if ($candidate->isExpired() || $candidate->getAttempts() >= self::MAX_ATTEMPTS) {
                $this->em->remove($candidate);
                continue;
            }
            if (hash_equals($candidate->getCodeHash(), $submitted)) {
                $this->em->remove($candidate);
                $valid = true;
                continue;
            }
            $candidate->registerAttempt();
            if ($candidate->getAttempts() >= self::MAX_ATTEMPTS) {
                $this->em->remove($candidate);
            }
        }
        $this->em->flush();

        return $valid;
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', 'sign-in|'.$code, $this->secret);
    }
}
