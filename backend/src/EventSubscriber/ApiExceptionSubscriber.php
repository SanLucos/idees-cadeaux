<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\TranslatableApiExceptionInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ensures every API error response is application/problem+json with a
 * stable, translatable `code` field (CLAUDE.md règle 7). Runs before
 * API Platform's own error normalizer so it can fully own the response
 * for our domain exceptions.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 64],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $throwable = $event->getThrowable();

        [$status, $code, $detail] = match (true) {
            $throwable instanceof TranslatableApiExceptionInterface => [
                $throwable->getStatusCode(),
                $throwable->getErrorCode(),
                $throwable->getMessage(),
            ],
            $throwable instanceof HttpExceptionInterface => [
                $throwable->getStatusCode(),
                self::codeFromStatus($throwable->getStatusCode()),
                $throwable->getMessage(),
            ],
            default => [500, 'server.internal_error', 'An unexpected error occurred.'],
        };

        $event->setResponse(new JsonResponse(
            [
                'type' => 'about:blank',
                'title' => self::codeFromStatus($status),
                'status' => $status,
                'detail' => $detail,
                'code' => $code,
            ],
            $status,
            ['Content-Type' => 'application/problem+json'],
        ));
    }

    private static function codeFromStatus(int $status): string
    {
        return match ($status) {
            400 => 'request.invalid',
            401 => 'auth.required',
            403 => 'access.denied',
            404 => 'resource.not_found',
            405 => 'request.method_not_allowed',
            409 => 'request.conflict',
            422 => 'request.unprocessable',
            429 => 'request.rate_limited',
            default => $status >= 500 ? 'server.internal_error' : 'request.failed',
        };
    }
}
