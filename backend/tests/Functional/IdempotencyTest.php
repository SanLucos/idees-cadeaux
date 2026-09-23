<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Idempotency-Key (spec §7, §8): what the offline outbox relies on to
 * replay a write whose answer it never got.
 */
final class IdempotencyTest extends AuthTestCase
{
    public function testAReplayedWriteRunsOnceAndAnswersTheSame(): void
    {
        $owner = $this->registerVerifyAndLogin('id-owner@example.com');
        $friend = $this->registerVerifyAndLogin('id-friend@example.com');
        $this->befriend($owner, 'id-owner@example.com', $friend, 'id-friend@example.com');
        $idea = static::createClient()->request('POST', '/api/ideas', ['auth_bearer' => $owner, 'json' => ['title' => 'Casque']])->toArray()['id'];

        $reserve = fn () => static::createClient()->request('POST', '/api/reservations', [
            'auth_bearer' => $friend, 'headers' => ['Idempotency-Key' => 'k-reserve'], 'json' => ['ideaId' => $idea],
        ]);
        $first = $reserve();
        $reservation = $first->toArray()['reservation']['id'];

        // Converting is not naturally idempotent: the reservation is gone after the first call.
        $convert = fn () => static::createClient()->request('POST', "/api/reservations/{$reservation}/convert-to-contribution", [
            'auth_bearer' => $friend, 'headers' => ['Idempotency-Key' => 'k-convert'], 'json' => [],
        ]);
        $converted = $convert();
        self::assertSame(201, $converted->getStatusCode());
        $replayed = $convert();
        self::assertSame(201, $replayed->getStatusCode(), 'replayed, not re-run (which would be a 404)');
        self::assertSame('true', $replayed->getHeaders()['idempotent-replayed'][0] ?? null);
        self::assertSame($converted->toArray()['id'], $replayed->toArray()['id']);

        $again = $reserve();
        self::assertSame(201, $again->getStatusCode(), 'the old answer, even though the idea is now in a contribution');

        // One key, one request.
        static::createClient()->request('POST', '/api/comments', [
            'auth_bearer' => $friend, 'headers' => ['Idempotency-Key' => 'k-reserve'], 'json' => ['ideaId' => $idea, 'body' => 'x'],
        ]);
        self::assertResponseStatusCodeSame(422);

        // Keys are per user: the owner's k-convert is their own.
        static::createClient()->request('POST', '/api/ideas', ['auth_bearer' => $owner, 'headers' => ['Idempotency-Key' => 'k-convert'], 'json' => ['title' => 'Other']]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testADeterministicErrorIsReplayedToo(): void
    {
        $alice = $this->registerVerifyAndLogin('id-error@example.com');
        $post = fn () => static::createClient()->request('POST', '/api/ideas', [
            'auth_bearer' => $alice, 'headers' => ['Idempotency-Key' => 'k-bad'], 'json' => ['title' => ''],
        ]);

        self::assertSame(422, $post()->getStatusCode());
        $replay = $post();
        self::assertSame(422, $replay->getStatusCode());
        self::assertSame('validation.idea_title_invalid', $replay->toArray(false)['code']);
    }
}
