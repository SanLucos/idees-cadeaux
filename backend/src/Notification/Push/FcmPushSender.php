<?php

declare(strict_types=1);

namespace App\Notification\Push;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Firebase Cloud Messaging, HTTP v1 (spec §2: FCM, which relays APNs
 * for iOS). Authenticates with a service account (OAuth2 JWT bearer
 * grant, token cached ~50 min).
 *
 * Inactive until FCM_PROJECT_ID and FCM_SERVICE_ACCOUNT_JSON are set
 * (spec §11 décision 27) — like the Google/Apple client ids of lot 1 —
 * so the whole pipeline runs in dev without a Firebase project.
 */
final class FcmPushSender implements PushSender
{
    private const string SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(FCM_PROJECT_ID)%')]
        private readonly string $projectId,
        #[Autowire('%env(FCM_SERVICE_ACCOUNT_JSON)%')]
        private readonly string $serviceAccountJson,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->projectId && '' !== $this->serviceAccountJson;
    }

    public function send(array $tokens, string $title, string $body, array $data): array
    {
        if (!$this->isConfigured() || [] === $tokens) {
            return [];
        }

        $accessToken = $this->accessToken();
        $invalid = [];
        foreach ($tokens as $token) {
            $response = $this->httpClient->request('POST', \sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $this->projectId), [
                'auth_bearer' => $accessToken,
                'json' => ['message' => [
                    'token' => $token->getToken(),
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => $data,
                ]],
            ]);

            $status = $response->getStatusCode();
            if ($status < 300) {
                continue;
            }

            $error = $response->toArray(false)['error']['details'][0]['errorCode'] ?? $response->toArray(false)['error']['status'] ?? '';
            // Spec §5.11 "nettoyage des tokens invalides".
            if (404 === $status || \in_array($error, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
                $invalid[] = $token;
            } else {
                $this->logger->warning('FCM send failed: {status} {error}', ['status' => $status, 'error' => $error]);
            }
        }

        return $invalid;
    }

    private function accessToken(): string
    {
        return $this->cache->get('fcm_access_token', function (ItemInterface $item): string {
            $item->expiresAfter(3000);
            $account = json_decode($this->serviceAccountJson, true, flags: \JSON_THROW_ON_ERROR);
            $now = time();

            $jws = (new JWSBuilder(new AlgorithmManager([new RS256()])))
                ->create()
                ->withPayload((string) json_encode([
                    'iss' => $account['client_email'],
                    'scope' => self::SCOPE,
                    'aud' => $account['token_uri'],
                    'iat' => $now,
                    'exp' => $now + 3600,
                ]))
                ->addSignature(JWKFactory::createFromKey($account['private_key']), ['alg' => 'RS256', 'typ' => 'JWT'])
                ->build();

            return $this->httpClient->request('POST', $account['token_uri'], [
                'body' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => (new CompactSerializer())->serialize($jws, 0),
                ],
            ])->toArray()['access_token'];
        });
    }
}
