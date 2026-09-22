<?php

declare(strict_types=1);

namespace App\Tests\Visibility;

/**
 * Smoke test for the visibility harness itself (lot 0). Real visibility
 * cases (suggestions, reservations, private ideas, etc.) are added as
 * their resources land, starting lot 3.
 */
final class PublicEndpointTest extends VisibilityTestCase
{
    public function testHealthCheckIsPubliclyReachable(): void
    {
        $response = static::createClient()->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertJsonEquals(['status' => 'ok']);
    }
}
