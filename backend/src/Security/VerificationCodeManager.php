<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Enum\VerificationCodePurpose;
use App\Entity\User;
use App\Entity\VerificationCode;
use App\Repository\VerificationCodeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Issues and checks the 6-digit codes behind email verification and
 * password reset (spec §5.1: "vérification de l'email (lien ou
 * code)"). A code is chosen over a link because no deep-link/universal
 * link infrastructure exists yet (that's spec §5.16/lot 7 bis); the
 * spec explicitly allows either.
 */
final class VerificationCodeManager
{
    private const int TTL_SECONDS = 900;
    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly VerificationCodeRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly string $secret,
    ) {
    }

    public function issue(User $user, VerificationCodePurpose $purpose): string
    {
        $this->repository->invalidateActiveFor($user, $purpose);

        $code = str_pad((string) random_int(0, 999_999), 6, '0', \STR_PAD_LEFT);

        $entity = new VerificationCode(
            $user,
            $purpose,
            $this->hash($code),
            new \DateTimeImmutable('+'.self::TTL_SECONDS.' seconds'),
        );

        $this->em->persist($entity);
        $this->em->flush();

        return $code;
    }

    public function verify(User $user, VerificationCodePurpose $purpose, string $submittedCode): bool
    {
        $candidates = $this->repository->findActiveForUser($user, $purpose);
        $submittedHash = $this->hash($submittedCode);

        foreach ($candidates as $candidate) {
            if ($candidate->isExpired() || $candidate->getAttempts() >= self::MAX_ATTEMPTS) {
                $this->em->remove($candidate);
                continue;
            }

            if (hash_equals($candidate->getCodeHash(), $submittedHash)) {
                $this->em->remove($candidate);
                $this->em->flush();

                return true;
            }

            $candidate->registerAttempt();
            if ($candidate->getAttempts() >= self::MAX_ATTEMPTS) {
                $this->em->remove($candidate);
            }
        }

        $this->em->flush();

        return false;
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, $this->secret);
    }
}
