<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\RefreshToken;
use App\Message\SendSignInEmail;
use App\Repository\UserRepository;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Sign-in without a password (spec §11 décision 68, issue #8): an email
 * with a code and a link carrying it, for every account, creating the
 * account on first use, and a long session.
 */
final class EmailSignInTest extends AuthTestCase
{
    private const int THIRTY_DAYS = 2592000;

    public function testAFirstSignInCreatesAVerifiedAccountWithALongSession(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $this->send('new-esi@example.com', 'en');
        $message = $this->lastMessageFor('new-esi@example.com');
        self::assertTrue($message->newAccount);
        self::assertSame('en', $message->locale);
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'new-esi@example.com']), 'nothing is created before the code is used');

        $response = static::createClient()->request('POST', '/api/auth/email-sign-in/verify', [
            'json' => ['email' => ' New-ESI@example.com ', 'code' => $message->code, 'locale' => 'en', 'id' => $id],
        ]);
        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertSame($id, $body['user']['id']);
        self::assertFalse($body['user']['isOnboarded']);
        self::assertSame('en', $body['user']['locale']);

        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'new-esi@example.com']);
        self::assertNotNull($user->getEmailVerifiedAt());
        self::assertNull($user->getPasswordHash());

        $this->assertLongSession($body['refresh_token']);

        static::createClient()->request('GET', '/api/users/me', ['auth_bearer' => $body['token']]);
        self::assertResponseIsSuccessful();
    }

    public function testAnAccountWithAPasswordCanSignInWithTheCodeToo(): void
    {
        $this->registerAndVerify('pwd-esi@example.com');
        $before = static::createClient()->request('GET', '/api/users/me', ['auth_bearer' => $this->login('pwd-esi@example.com')])->toArray()['id'];

        $this->send('pwd-esi@example.com');
        $message = $this->lastMessageFor('pwd-esi@example.com');
        self::assertFalse($message->newAccount);

        $body = static::createClient()->request('POST', '/api/auth/email-sign-in/verify', [
            'json' => ['email' => 'pwd-esi@example.com', 'code' => $message->code],
        ])->toArray();
        self::assertSame($before, $body['user']['id']);

        // The password still works.
        $this->login('pwd-esi@example.com');
    }

    public function testTheCodeVerifiesAnAddressRegisteredButNeverVerified(): void
    {
        static::createClient()->request('POST', '/api/auth/register', ['json' => ['email' => 'unverified-esi@example.com', 'password' => 'correcthorsebattery']]);
        $this->send('unverified-esi@example.com');

        $this->verify('unverified-esi@example.com', $this->lastMessageFor('unverified-esi@example.com')->code);
        self::assertResponseStatusCodeSame(200);
        $this->login('unverified-esi@example.com');
    }

    public function testSendingAnswersTheSameWhetherOrNotTheAccountExists(): void
    {
        $this->registerAndVerify('known-esi@example.com');
        $known = static::createClient()->request('POST', '/api/auth/email-sign-in', ['json' => ['email' => 'known-esi@example.com']]);
        $unknown = static::createClient()->request('POST', '/api/auth/email-sign-in', ['json' => ['email' => 'unknown-esi@example.com']]);

        self::assertSame(200, $known->getStatusCode());
        self::assertSame($known->toArray(), $unknown->toArray());

        static::createClient()->request('POST', '/api/auth/email-sign-in', ['json' => ['email' => 'not-an-email']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testACodeWorksOnceAndOnlyTheLatestOne(): void
    {
        $this->send('once-esi@example.com');
        $first = $this->lastMessageFor('once-esi@example.com')->code;
        $this->send('once-esi@example.com');
        $second = $this->lastMessageFor('once-esi@example.com')->code;

        if ($first !== $second) {
            $this->verify('once-esi@example.com', $first);
            self::assertResponseStatusCodeSame(422);
            self::assertJsonContains(['code' => 'auth.invalid_code']);
        }

        $this->verify('once-esi@example.com', $second);
        self::assertResponseStatusCodeSame(200);
        $this->verify('once-esi@example.com', $second);
        self::assertResponseStatusCodeSame(422);
    }

    public function testACodeDiesAfterFiveWrongAttempts(): void
    {
        $this->send('brute-esi@example.com');
        $code = $this->lastMessageFor('brute-esi@example.com')->code;
        $wrong = '000000' === $code ? '111111' : '000000';

        for ($i = 0; $i < 5; ++$i) {
            $this->verify('brute-esi@example.com', $wrong);
            self::assertResponseStatusCodeSame(422);
        }
        $this->verify('brute-esi@example.com', $code);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSendingIsRateLimitedPerAddress(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->send('flood-esi@example.com');
        }
        static::createClient()->request('POST', '/api/auth/email-sign-in', ['json' => ['email' => 'flood-esi@example.com']]);
        self::assertResponseStatusCodeSame(429);
        self::assertJsonContains(['code' => 'auth.too_many_attempts']);
    }

    public function testALongSessionStaysLongAcrossRotationsAndAPasswordLoginDoesNot(): void
    {
        $this->send('rotate-esi@example.com');
        $body = $this->verify('rotate-esi@example.com', $this->lastMessageFor('rotate-esi@example.com')->code)->toArray();

        $rotated = static::createClient()->request('POST', '/api/auth/refresh', ['json' => ['refresh_token' => $body['refresh_token']]])->toArray();
        self::assertNotSame($body['refresh_token'], $rotated['refresh_token']);
        $this->assertLongSession($rotated['refresh_token']);

        // A password login keeps the usual ~30 days.
        $this->registerAndVerify('short-esi@example.com');
        $login = static::createClient()->request('POST', '/api/auth/login', ['json' => ['email' => 'short-esi@example.com', 'password' => 'correcthorsebattery']])->toArray();
        $token = $this->refreshToken($login['refresh_token']);
        self::assertFalse($token->isLongSession());
        self::assertLessThanOrEqual(time() + self::THIRTY_DAYS + 60, $token->getValid()->getTimestamp());
    }

    public function testInABrowserTheCookieLastsAsLongAsTheSession(): void
    {
        $this->send('web-esi@example.com');
        $code = $this->lastMessageFor('web-esi@example.com')->code;
        $response = static::createClient()->request('POST', '/api/auth/email-sign-in/verify', [
            'headers' => ['X-Client' => 'web'],
            'json' => ['email' => 'web-esi@example.com', 'code' => $code],
        ]);
        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey('refresh_token', $response->toArray());

        $cookie = $this->cookieExpiry($response->getHeaders()['set-cookie'] ?? []);
        self::assertNotNull($cookie);
        self::assertGreaterThan(time() + 300 * 86400, $cookie['expires']);

        $browser = static::createClient();
        $browser->getCookieJar()->set(new Cookie('refresh_token', $cookie['value'], null, '/api/auth', 'localhost'));
        $refreshed = $browser->request('POST', '/api/auth/refresh', ['headers' => ['X-Client' => 'web']]);
        self::assertSame(200, $refreshed->getStatusCode());
        $rotated = $this->cookieExpiry($refreshed->getHeaders()['set-cookie'] ?? []);
        self::assertNotNull($rotated);
        self::assertGreaterThan(time() + 300 * 86400, $rotated['expires']);
    }

    private function send(string $email, string $locale = 'fr'): void
    {
        static::createClient()->request('POST', '/api/auth/email-sign-in', ['json' => ['email' => $email, 'locale' => $locale]]);
        self::assertResponseStatusCodeSame(200);
    }

    private function verify(string $email, string $code): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return static::createClient()->request('POST', '/api/auth/email-sign-in/verify', ['json' => ['email' => $email, 'code' => $code]]);
    }

    private function lastMessageFor(string $email): SendSignInEmail
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $matching = array_values(array_filter(
            array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent()),
            static fn ($message): bool => $message instanceof SendSignInEmail && $message->email === $email,
        ));
        self::assertNotEmpty($matching, 'No sign-in email was queued for '.$email);

        return end($matching);
    }

    private function refreshToken(string $value): RefreshToken
    {
        $token = self::getContainer()->get(RefreshTokenManagerInterface::class)->get($value);
        self::assertInstanceOf(RefreshToken::class, $token);

        return $token;
    }

    private function assertLongSession(string $value): void
    {
        $token = $this->refreshToken($value);
        self::assertTrue($token->isLongSession());
        self::assertGreaterThan(time() + 300 * 86400, $token->getValid()->getTimestamp());
    }

    /**
     * @param string[] $headers
     *
     * @return array{value: string, expires: int}|null
     */
    private function cookieExpiry(array $headers): ?array
    {
        foreach ($headers as $raw) {
            if (str_starts_with($raw, 'refresh_token=') && preg_match('/expires=([^;]+)/i', $raw, $m)) {
                $value = substr($raw, \strlen('refresh_token='), (int) strpos($raw.';', ';') - \strlen('refresh_token='));

                return ['value' => urldecode($value), 'expires' => (int) strtotime($m[1])];
            }
        }

        return null;
    }
}
