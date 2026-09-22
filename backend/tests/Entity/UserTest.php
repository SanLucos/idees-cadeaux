<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Enum\UserType;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class UserTest extends TestCase
{
    public function testIdIsGeneratedAsUuidV7WhenNotSupplied(): void
    {
        $user = new User(UserType::Regular, 'Alice');

        self::assertInstanceOf(UuidV7::class, $user->getId());
    }

    public function testClientSuppliedIdIsPreservedForOfflineIdempotency(): void
    {
        $id = Uuid::v7();

        $user = new User(UserType::Regular, 'Alice', id: $id);

        self::assertTrue($id->equals($user->getId()));
    }

    public function testCreatedAtAndUpdatedAtAreStampedOnConstruction(): void
    {
        $user = new User(UserType::Regular, 'Alice');

        self::assertEquals($user->getCreatedAt(), $user->getUpdatedAt());
    }

    public function testManagedProfileHasNoEmailByDefault(): void
    {
        $child = new User(UserType::Managed, 'Petit Bob');

        self::assertTrue($child->isManaged());
        self::assertNull($child->getEmail());
    }

    public function testIsNotDeletedByDefault(): void
    {
        $user = new User(UserType::Regular, 'Alice');

        self::assertFalse($user->isDeleted());
        self::assertNull($user->getDeletedAt());
    }
}
