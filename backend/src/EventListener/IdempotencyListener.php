<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\ApiProblemException;
use App\Security\ActingContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

/**
 * `Idempotency-Key` (spec §7, §8): the offline outbox may send the same
 * write twice (lost response, app killed mid-flush). The first answer
 * to a key is stored — success or a deterministic 4xx — and replayed
 * as-is for the same user, method and path; nothing runs twice. Server
 * errors (5xx) aren't stored, so a retry really retries.
 * Records are dropped after 48 h (App\Scheduler\PurgeIdempotencyKeysTask).
 */
final class IdempotencyListener
{
    private const array METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const string REPLAYED = '_idempotency_replayed';

    public function __construct(
        private readonly Connection $connection,
        private readonly ActingContext $acting,
    ) {
    }

    /** After the firewall (8) and the X-Acting-As guard (4). */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 2)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (null === $key = $this->keyOf($event->isMainRequest(), $request)) {
            return;
        }

        $record = $this->connection->fetchAssociative(
            'SELECT method, path, status, body FROM idempotency_record WHERE user_id = :user AND key = :key',
            ['user' => $key[0], 'key' => $key[1]],
        );
        if (false === $record) {
            return;
        }
        if ($record['method'] !== $request->getMethod() || $record['path'] !== $request->getPathInfo()) {
            throw new ApiProblemException('idempotency.key_reused', 'This Idempotency-Key was used for another request.', 422);
        }

        $request->attributes->set(self::REPLAYED, true);
        $event->setResponse(new Response($record['body'], (int) $record['status'], [
            'Content-Type' => (int) $record['status'] >= 400 ? 'application/problem+json' : 'application/json',
            'Idempotent-Replayed' => 'true',
        ]));
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        if ($request->attributes->get(self::REPLAYED) || $response->getStatusCode() >= 500 || 401 === $response->getStatusCode()) {
            return;
        }
        if (null === $key = $this->keyOf($event->isMainRequest(), $request)) {
            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO idempotency_record (id, user_id, key, method, path, status, body, created_at)
             VALUES (:id, :user, :key, :method, :path, :status, :body, NOW())
             ON CONFLICT (user_id, key) DO NOTHING',
            [
                'id' => Uuid::v7()->toRfc4122(),
                'user' => $key[0],
                'key' => $key[1],
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'status' => $response->getStatusCode(),
                'body' => (string) $response->getContent(),
            ],
        );
    }

    /**
     * @return array{string, string}|null [adult's user id, key]
     */
    private function keyOf(bool $isMainRequest, Request $request): ?array
    {
        $key = $request->headers->get('Idempotency-Key');
        if (!$isMainRequest || null === $key || !\in_array($request->getMethod(), self::METHODS, true) || !str_starts_with($request->getPathInfo(), '/api')) {
            return null;
        }
        if ('' === $key || \strlen($key) > 100) {
            throw new ApiProblemException('idempotency.key_invalid', 'Idempotency-Key must be 1 to 100 characters.', 400);
        }

        $human = $this->acting->human();

        return null !== $human ? [$human->getId()->toRfc4122(), $key] : null;
    }
}
