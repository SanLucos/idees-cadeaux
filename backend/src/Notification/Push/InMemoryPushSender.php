<?php

declare(strict_types=1);

namespace App\Notification\Push;

/**
 * Test double (config/services.yaml, when@test): records pushes; a
 * token starting with "invalid-" is reported invalid.
 */
final class InMemoryPushSender implements PushSender
{
    /** @var list<array{token: string, title: string, body: string, data: array<string, string>}> */
    public static array $sent = [];

    public function send(array $tokens, string $title, string $body, array $data): array
    {
        $invalid = [];
        foreach ($tokens as $token) {
            if (str_starts_with($token->getToken(), 'invalid-')) {
                $invalid[] = $token;
                continue;
            }
            self::$sent[] = ['token' => $token->getToken(), 'title' => $title, 'body' => $body, 'data' => $data];
        }

        return $invalid;
    }
}
