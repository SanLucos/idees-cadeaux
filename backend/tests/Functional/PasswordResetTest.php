<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Enum\VerificationCodePurpose;

final class PasswordResetTest extends AuthTestCase
{
    public function testForgotPasswordAnswersIdenticallyWhetherOrNotTheAccountExists(): void
    {
        $this->registerAndVerify('reset@example.com');

        $known = static::createClient()->request('POST', '/api/auth/forgot-password', [
            'json' => ['email' => 'reset@example.com'],
        ]);
        $unknown = static::createClient()->request('POST', '/api/auth/forgot-password', [
            'json' => ['email' => 'nobody@example.com'],
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($known->toArray(), $unknown->toArray());
    }

    public function testResetPasswordChangesCredentialsAndRevokesRefreshTokens(): void
    {
        $this->registerAndVerify('reset2@example.com');
        $login = static::createClient()->request('POST', '/api/auth/login', [
            'json' => ['email' => 'reset2@example.com', 'password' => 'correcthorsebattery'],
        ])->toArray();

        static::createClient()->request('POST', '/api/auth/forgot-password', [
            'json' => ['email' => 'reset2@example.com'],
        ]);
        $code = $this->lastCodeFor('reset2@example.com', VerificationCodePurpose::ResetPassword);

        static::createClient()->request('POST', '/api/auth/reset-password', [
            'json' => ['email' => 'reset2@example.com', 'code' => $code, 'newPassword' => 'brandnewpassword1'],
        ]);
        self::assertResponseStatusCodeSame(200);

        // Old password no longer works.
        static::createClient()->request('POST', '/api/auth/login', [
            'json' => ['email' => 'reset2@example.com', 'password' => 'correcthorsebattery'],
        ]);
        self::assertResponseStatusCodeSame(401);

        // New password does.
        static::createClient()->request('POST', '/api/auth/login', [
            'json' => ['email' => 'reset2@example.com', 'password' => 'brandnewpassword1'],
        ]);
        self::assertResponseStatusCodeSame(200);

        // The refresh token issued before the reset was revoked.
        static::createClient()->request('POST', '/api/auth/refresh', [
            'json' => ['refresh_token' => $login['refresh_token']],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testResetPasswordRejectsAWrongCode(): void
    {
        $this->registerAndVerify('reset3@example.com');
        static::createClient()->request('POST', '/api/auth/forgot-password', [
            'json' => ['email' => 'reset3@example.com'],
        ]);

        static::createClient()->request('POST', '/api/auth/reset-password', [
            'json' => ['email' => 'reset3@example.com', 'code' => '000000', 'newPassword' => 'brandnewpassword1'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }
}
