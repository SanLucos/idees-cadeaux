<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Entity\Enum\UserType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Foundation for /sync tombstones (spec §8): a soft-deleted row must
 * disappear from ordinary queries but remain readable when the filter
 * is explicitly disabled.
 */
final class SoftDeleteFilterTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testDeletedUserIsHiddenFromDefaultQueries(): void
    {
        $user = new User(UserType::Regular, 'Bientôt supprimée');
        $this->em->persist($user);
        $this->em->flush();
        $id = $user->getId();

        $user->markDeleted();
        $this->em->flush();
        $this->em->clear();

        self::assertNull($this->em->getRepository(User::class)->find($id));
    }

    public function testDeletedUserIsVisibleWithFilterDisabled(): void
    {
        $user = new User(UserType::Regular, 'Bientôt supprimée');
        $this->em->persist($user);
        $this->em->flush();
        $id = $user->getId();

        $user->markDeleted();
        $this->em->flush();
        $this->em->clear();

        $this->em->getFilters()->disable('soft_deleteable');
        try {
            self::assertNotNull($this->em->getRepository(User::class)->find($id));
        } finally {
            $this->em->getFilters()->enable('soft_deleteable');
        }
    }
}
