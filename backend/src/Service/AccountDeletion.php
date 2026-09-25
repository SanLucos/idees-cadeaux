<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\ContributionStatus;
use App\Entity\User;
use App\Message\SendAccountEmail;
use App\Repository\UserRepository;
use App\Serializer\IdeaNormalizer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Suppression du compte et des données" (spec §5.13), for adults and,
 * through their manager, child profiles (§5.15):
 *
 * - schedule(): 14 days of grace. The account is suspended
 *   (User::isSuspended): sessions and push tokens revoked, nothing sent
 *   to or about it, no new friend request, email not reusable; its
 *   contents stay as they are. An email carries a cancel link.
 * - cancel(): back to normal, by signing in or through that link.
 * - erase(): for good, once the grace period is over
 *   (App\Scheduler\DeleteScheduledProfilesTask). The database cascades
 *   take the account, its ideas and everything on them, and everything
 *   it created on other lists; a contribution it initiated elsewhere is
 *   closed first, the others' pledges kept. Its images are deleted,
 *   friends' devices drop what disappeared at their next sync (spec §11
 *   décision 32), and an information email is sent.
 */
final class AccountDeletion
{
    public const string GRACE = '14 days';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly UserRepository $users,
        #[Autowire(service: 'default.storage')]
        private readonly FilesystemOperator $storage,
        private readonly MessageBusInterface $bus,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        private readonly string $secret,
    ) {
    }

    public function schedule(User $user): void
    {
        if (null !== $user->getDeletionScheduledAt()) {
            return;
        }

        $user->setDeletionScheduledAt(new \DateTimeImmutable('+'.self::GRACE));
        $this->em->flush();

        // Every session and push token (spec §5.13), the children's included.
        $this->em->createQuery('DELETE FROM App\Entity\RefreshToken r WHERE r.username = :email')
            ->setParameter('email', $user->getEmail())
            ->execute();
        $this->connection->executeStatement(
            'DELETE FROM device_token WHERE user_id IN (SELECT id FROM app_user WHERE id = :id OR managed_by_id = :id)',
            ['id' => $user->getId()->toRfc4122()],
        );

        if (null !== $user->getEmail()) {
            $this->bus->dispatch(new SendAccountEmail(
                'deletion_requested',
                $user->getEmail(),
                $user->getLocale(),
                ['%date%' => $this->formatDate($user->getDeletionScheduledAt(), $user->getLocale())],
                $this->cancelUrl($user),
            ));
        }
    }

    public function cancel(User $user): void
    {
        $user->setDeletionScheduledAt(null);
        $this->em->flush();
    }

    public function cancelUrl(User $user): string
    {
        return $this->urls->generate('account_cancel_deletion', [
            'user' => $user->getId()->toRfc4122(),
            'signature' => $this->signature($user),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /** Only valid for the deletion it was sent for: a later request needs a new email. */
    public function isValidCancelSignature(User $user, string $signature): bool
    {
        return null !== $user->getDeletionScheduledAt() && hash_equals($this->signature($user), $signature);
    }

    /**
     * Accounts and child profiles whose grace period is over.
     *
     * @return int how many were erased
     */
    public function eraseDue(\DateTimeImmutable $now = new \DateTimeImmutable()): int
    {
        $due = $this->users->createQueryBuilder('u')
            ->andWhere('u.deletionScheduledAt IS NOT NULL AND u.deletionScheduledAt < :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        $erased = 0;
        foreach ($due as $user) {
            // A child already taken with its manager's account.
            if (null !== $this->users->find($user->getId())) {
                $this->erase($user);
                ++$erased;
            }
        }

        return $erased;
    }

    public function erase(User $user): void
    {
        $ids = array_map(
            static fn (array $row) => (string) $row['id'],
            $this->connection->fetchAllAssociative('SELECT id FROM app_user WHERE id = :id OR managed_by_id = :id', ['id' => $user->getId()->toRfc4122()]),
        );
        $images = $this->imagesOf($ids);
        $email = $this->emailFor($user);

        $this->connection->transactional(function (Connection $db) use ($ids): void {
            $params = ['ids' => $ids, 'open' => ContributionStatus::Open->value, 'closed' => ContributionStatus::Closed->value, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')];
            $types = ['ids' => ArrayParameterType::STRING];

            // Their contributions on other lists: closed, others' pledges kept.
            $db->executeStatement('UPDATE contribution SET status = :closed, closed_at = :now, updated_at = :now WHERE initiator_id IN (:ids) AND status = :open', $params, $types);
            // Notifications that name them, in anyone's centre.
            $db->executeStatement(
                "DELETE FROM notification WHERE payload::jsonb -> 'actor' ->> 'id' IN (:ids) OR payload::jsonb -> 'friend' ->> 'id' IN (:ids) OR payload::jsonb -> 'owner' ->> 'id' IN (:ids)",
                $params,
                $types,
            );
            // Children first (they point at the account), then the account; cascades do the rest.
            $db->executeStatement('DELETE FROM app_user WHERE managed_by_id IN (:ids)', $params, $types);
            $db->executeStatement('DELETE FROM app_user WHERE id IN (:ids)', $params, $types);
        });
        $this->em->clear();

        foreach ($images as $path) {
            try {
                $this->storage->delete($path);
            } catch (FilesystemException $e) {
                $this->logger->warning('Image of an erased account could not be deleted.', ['path' => $path, 'exception' => $e]);
            }
        }

        if (null !== $email) {
            $this->bus->dispatch($email);
        }
    }

    /**
     * Avatars, and the images of every idea they own or wrote (thumbnails included).
     *
     * @param string[] $ids
     *
     * @return string[]
     */
    private function imagesOf(array $ids): array
    {
        $paths = $this->connection->fetchFirstColumn(
            'SELECT avatar_path FROM app_user WHERE id IN (:ids) AND avatar_path IS NOT NULL
             UNION SELECT image_path FROM idea WHERE (owner_id IN (:ids) OR author_id IN (:ids)) AND image_path IS NOT NULL',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        $all = [];
        foreach ($paths as $path) {
            $all[] = (string) $path;
            if (str_ends_with((string) $path, '.jpg') && str_starts_with((string) $path, 'ideas/')) {
                $all[] = IdeaNormalizer::thumbnailPath((string) $path);
            }
        }

        return array_values(array_unique($all));
    }

    private function emailFor(User $user): ?SendAccountEmail
    {
        if (!$user->isManaged()) {
            return null !== $user->getEmail() ? new SendAccountEmail('deleted', $user->getEmail(), $user->getLocale()) : null;
        }

        // A child profile deleted on its own: its manager is told (unless leaving too).
        $manager = $user->getManagedBy();
        if (null === $manager || null === $manager->getEmail() || null !== $manager->getDeletionScheduledAt()) {
            return null;
        }

        return new SendAccountEmail('profile_deleted', $manager->getEmail(), $manager->getLocale(), ['%profile%' => (string) $user->getDisplayName()]);
    }

    private function signature(User $user): string
    {
        $scheduled = $user->getDeletionScheduledAt()?->format(\DATE_ATOM) ?? '';

        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'cancel-deletion|'.$user->getId()->toRfc4122().'|'.$scheduled, $this->secret, true)), '+/', '-_'), '=');
    }

    private function formatDate(?\DateTimeImmutable $date, string $locale): string
    {
        return (new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE))->format($date ?? new \DateTimeImmutable());
    }
}
