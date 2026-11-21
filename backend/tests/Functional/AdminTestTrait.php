<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Superadmin set up as `app:admin:grant` would (spec §5.17).
 */
trait AdminTestTrait
{
    protected function grantSuperAdmin(string $email): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        $user->setSuperAdmin(true);
        $em->flush();
        $em->clear();
    }

    /**
     * @return array<string, mixed> the administration's row for this user
     */
    protected function adminRow(string $adminToken, string $userId, ?string $relativeTo = null): array
    {
        $query = null !== $relativeTo ? "&relativeTo={$relativeTo}" : '';
        foreach ($this->expect(200, $adminToken, 'GET', "/api/admin/users?page=1{$query}")['items'] as $row) {
            if ($row['id'] === $userId) {
                return $row;
            }
        }
        self::fail('User not listed.');
    }
}
