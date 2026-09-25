<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Spec §9 "Observabilité" (request ids, the app's error reports) and
 * §5.13 "Politique de confidentialité accessible dans l'appli".
 */
final class ObservabilityAndLegalTest extends AuthTestCase
{
    public function testEveryResponseCarriesARequestIdTheCallerMayChoose(): void
    {
        $response = static::createClient()->request('GET', '/api/health');
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $response->getHeaders()['x-request-id'][0]);

        $response = static::createClient()->request('GET', '/api/health', ['headers' => ['X-Request-Id' => 'app-1234abcd']]);
        self::assertSame('app-1234abcd', $response->getHeaders()['x-request-id'][0]);
    }

    public function testTheAppReportsItsErrorsWithoutSigningIn(): void
    {
        static::createClient()->request('POST', '/api/client-errors', ['json' => ['message' => 'TypeError: x is undefined', 'route' => '/tabs/list', 'platform' => 'android', 'extra' => 'ignored']]);
        self::assertResponseStatusCodeSame(204);

        static::createClient()->request('POST', '/api/client-errors', ['json' => ['stack' => 'no message']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testThePrivacyPolicyIsServedInBothLanguages(): void
    {
        $fr = static::createClient()->request('GET', '/privacy?lang=fr');
        self::assertSame(200, $fr->getStatusCode());
        self::assertStringContainsString('Politique de confidentialité', $fr->getContent());
        self::assertStringContainsString('14 jours', $fr->getContent(), 'the grace period is mentioned (spec §5.13)');
        self::assertStringContainsString('Lien de partage', $fr->getContent(), 'and the public view through share links');

        $en = static::createClient()->request('GET', '/privacy', ['headers' => ['Accept-Language' => 'en-GB,en;q=0.9']]);
        self::assertStringContainsString('Privacy policy', $en->getContent());
    }
}
