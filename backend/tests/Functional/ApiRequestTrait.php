<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Terse authenticated requests for the lot 4+ tests: `call()` returns
 * the status code and keeps the decoded body in `$this->body`.
 */
trait ApiRequestTrait
{
    /** @var array<mixed> */
    protected array $body = [];

    /**
     * @param array<string, mixed>|null $json
     */
    protected function call(string $token, string $method, string $path, ?array $json = null): int
    {
        $options = ['auth_bearer' => $token];
        if (null !== $json) {
            $options['json'] = $json;
            if ('PATCH' === $method) {
                $options['headers'] = ['Content-Type' => 'application/merge-patch+json'];
            }
        }

        $response = static::createClient()->request($method, $path, $options);
        $content = $response->getContent(false);
        $this->body = '' === $content ? [] : (array) json_decode($content, true);

        return $response->getStatusCode();
    }

    /**
     * Asserts the status and returns the body.
     *
     * @param array<string, mixed>|null $json
     *
     * @return array<mixed>
     */
    protected function expect(int $status, string $token, string $method, string $path, ?array $json = null): array
    {
        $actual = $this->call($token, $method, $path, $json);
        self::assertSame($status, $actual, \sprintf('%s %s → %d: %s', $method, $path, $actual, json_encode($this->body)));

        return $this->body;
    }
}
