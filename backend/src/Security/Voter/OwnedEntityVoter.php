<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\OwnedEntityInterface;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Gates write operations (Patch/Delete) on OwnedEntityInterface
 * resources to the owner alone. Read visibility (including a friend's
 * read-only access) is decided separately by
 * App\Doctrine\Extension\VisibleToOwnerOrFriendsExtension — a friend
 * can find the item but this voter still blocks them from editing it.
 */
final class OwnedEntityVoter extends Voter
{
    public const string OWNER = 'OWNER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::OWNER === $attribute && $subject instanceof OwnedEntityInterface;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (!$subject instanceof OwnedEntityInterface) {
            return false;
        }

        $user = $token->getUser();

        return $user instanceof User && $user === $subject->getUser();
    }
}
