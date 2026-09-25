<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Enum\VerificationCodePurpose;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Message\SendVerificationCodeEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Spec §5.13 "ré-authentification" before an export or a deletion: the
 * password when the account has one, otherwise a 6-digit code sent by
 * email (Google/Apple-only accounts). Rate-limited per account.
 */
final class Reauthentication
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly VerificationCodeManager $codes,
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'limiter.reauthentication')]
        private readonly RateLimiterFactory $limiter,
    ) {
    }

    public function sendCode(User $user): void
    {
        $this->consume($user);
        $code = $this->codes->issue($user, VerificationCodePurpose::Reauthenticate);
        $this->bus->dispatch(new SendVerificationCodeEmail($user->getId()->toRfc4122(), VerificationCodePurpose::Reauthenticate, $code));
    }

    /**
     * @param array<string, mixed> $body `password` or `code`
     */
    public function check(User $user, array $body): void
    {
        $this->consume($user);

        $password = \is_string($body['password'] ?? null) ? $body['password'] : '';
        $code = \is_string($body['code'] ?? null) ? trim($body['code']) : '';

        $valid = match (true) {
            '' !== $password => null !== $user->getPasswordHash() && $this->hasher->isPasswordValid($user, $password),
            '' !== $code => $this->codes->verify($user, VerificationCodePurpose::Reauthenticate, $code),
            default => throw new ApiProblemException('auth.reauthentication_required', 'Confirm with your password or an emailed code.', 422),
        };

        if (!$valid) {
            throw new ApiProblemException('auth.reauthentication_failed', 'Wrong password or code.', 422);
        }
    }

    private function consume(User $user): void
    {
        if (!$this->limiter->create($user->getId()->toRfc4122())->consume()->isAccepted()) {
            throw new ApiProblemException('auth.too_many_attempts', 'Too many attempts, try again later.', 429);
        }
    }
}
