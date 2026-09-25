<?php

declare(strict_types=1);

namespace App\Observability;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Spec §9 "Observabilité": every log line of a request carries its
 * `request_id` — the caller's `X-Request-Id` (the app sends one, so its
 * error reports match the server's logs) or a new one — echoed back in
 * the response. Never the user's email or token: only ids.
 */
#[AsMonologProcessor]
final class RequestId implements ResetInterface
{
    private ?string $id = null;
    private ?string $path = null;

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $given = (string) $event->getRequest()->headers->get('X-Request-Id', '');
        $this->id = 1 === preg_match('/^[A-Za-z0-9-]{8,64}$/', $given) ? $given : bin2hex(random_bytes(8));
        $this->path = $event->getRequest()->getMethod().' '.$event->getRequest()->getPathInfo();
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && null !== $this->id) {
            $event->getResponse()->headers->set('X-Request-Id', $this->id);
        }
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if (null === $this->id) {
            return $record;
        }

        return $record->with(extra: $record->extra + ['request_id' => $this->id, 'route' => $this->path]);
    }

    public function reset(): void
    {
        $this->id = null;
        $this->path = null;
    }
}
