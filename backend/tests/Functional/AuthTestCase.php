<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Entity\Enum\VerificationCodePurpose;
use App\Message\SendVerificationCodeEmail;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

abstract class AuthTestCase extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    /**
     * Rate limiters (login_throttling, limiter.friend_request) are
     * filesystem-backed and so, unlike the database
     * (dama/doctrine-test-bundle), persist across the whole test run
     * instead of resetting per test — every request in every test
     * reboots the kernel ($alwaysBootKernel), so an in-memory pool
     * isn't an option either, it would reset *within* a test too and
     * break tests that deliberately exercise a limit. Clearing the
     * pool once per test keeps state cumulative across a test's own
     * requests (what login-throttling tests need) without leaking into
     * unrelated tests (what login attempts in this class's other
     * helpers would otherwise trip).
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    /**
     * Registers, pulls the verification code straight from the
     * `async` in-memory transport (no real mailbox in tests — see
     * config/packages/messenger.yaml's when@test), and verifies.
     */
    protected function registerAndVerify(string $email, string $password = 'correcthorsebattery'): void
    {
        static::createClient()->request('POST', '/api/auth/register', [
            'json' => ['email' => $email, 'password' => $password],
        ]);
        self::assertResponseStatusCodeSame(201);

        $code = $this->lastCodeFor($email, VerificationCodePurpose::VerifyEmail);

        static::createClient()->request('POST', '/api/auth/verify-email', [
            'json' => ['email' => $email, 'code' => $code],
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    protected function registerVerifyAndLogin(string $email, string $password = 'correcthorsebattery'): string
    {
        $this->registerAndVerify($email, $password);

        return $this->login($email, $password);
    }

    protected function login(string $email, string $password = 'correcthorsebattery'): string
    {
        $response = static::createClient()->request('POST', '/api/auth/login', [
            'json' => ['email' => $email, 'password' => $password],
        ]);
        self::assertResponseStatusCodeSame(200);

        return $response->toArray()['token'];
    }

    protected function lastCodeFor(string $email, VerificationCodePurpose $purpose): string
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');

        $matching = array_values(array_filter(
            $transport->getSent(),
            static function ($envelope) use ($email, $purpose): bool {
                $message = $envelope->getMessage();

                return $message instanceof SendVerificationCodeEmail
                    && $purpose === $message->purpose
                    && self::userIdBelongsTo($message->userId, $email);
            },
        ));

        self::assertNotEmpty($matching, \sprintf('No verification code was queued for %s (%s).', $email, $purpose->value));

        $lastMessage = end($matching)->getMessage();
        \assert($lastMessage instanceof SendVerificationCodeEmail);

        return $lastMessage->code;
    }

    private static function userIdBelongsTo(string $userId, string $email): bool
    {
        $user = self::getContainer()->get(\App\Repository\UserRepository::class)->find($userId);

        return null !== $user && $user->getEmail() === $email;
    }
}
