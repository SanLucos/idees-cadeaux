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
