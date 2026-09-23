<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\FriendshipOrigin;
use App\Entity\Friendship;
use App\Entity\ManagedProfileInvitation;
use App\Entity\User;
use App\Exception\ApiProblemException;
use App\Message\SendManagedProfileInvitationEmail;
use App\Repository\FriendshipRepository;
use App\Repository\ManagedProfileInvitationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Spec §5.15 "rattacher un email", with a 6-digit code (spec §11
 * décision 26):
 *
 * 1. invite(): the manager names an email; one pending invitation per
 *    profile, valid 7 days. The answer never says whether that email
 *    already has an account (no enumeration through the manager).
 * 2. redeem(): the invitee proves the code. The email must still be
 *    free; the profile becomes an autonomous account, the manager's
 *    access ends, and manager and new account become friends.
 */
final class ManagedProfileInvitations
{
    public function __construct(
        private readonly ManagedProfileInvitationRepository $invitations,
        private readonly UserRepository $users,
        private readonly FriendshipRepository $friendships,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly string $secret,
    ) {
    }

    public function invite(User $profile, User $manager, string $email): ManagedProfileInvitation
    {
        $this->invitations->deleteForProfile($profile);

        $code = str_pad((string) random_int(0, 999_999), 6, '0', \STR_PAD_LEFT);
        $invitation = new ManagedProfileInvitation($profile, $email, $this->hash($code));
        $this->em->persist($invitation);
        $this->em->flush();

        $this->bus->dispatch(new SendManagedProfileInvitationEmail(
            $email,
            $code,
            (string) $profile->getDisplayName(),
            (string) $manager->getDisplayName(),
            $manager->getLocale(),
        ));

        return $invitation;
    }

    /**
     * @throws ApiProblemException `invitation.invalid` for any wrong/expired/unknown
     *                             code (one answer for all), `auth.email_already_registered`
     *                             once the code is proven but the email got taken meanwhile
     */
    public function redeem(string $email, string $code): User
    {
        $submitted = $this->hash($code);

        foreach ($this->invitations->findByEmail($email) as $invitation) {
            if (!$invitation->isUsable()) {
                continue;
            }
            if (!hash_equals($invitation->getCodeHash(), $submitted)) {
                $invitation->registerFailedAttempt();
                continue;
            }

            if (null !== $this->users->findOneBy(['email' => $email])) {
                throw new ApiProblemException('auth.email_already_registered', 'This email already has an account.', 409);
            }

            return $this->convert($invitation);
        }

        $this->em->flush();

        throw new ApiProblemException('invitation.invalid', 'Invalid or expired invitation code.', 422);
    }

    private function convert(ManagedProfileInvitation $invitation): User
    {
        $profile = $invitation->getProfile();
        $manager = $profile->getManagedBy();

        $profile->convertToAutonomous($invitation->getEmail());
        $this->invitations->deleteForProfile($profile);

        // Spec §5.15: an automatic friendship the new account may remove.
        if (null !== $manager && null === $this->friendships->findActiveBetween($manager, $profile)) {
            $this->em->persist(Friendship::createAccepted($manager, $profile, FriendshipOrigin::Conversion));
        }

        $this->em->flush();

        return $profile;
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, $this->secret);
    }
}
