<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ApiProblemException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/client-errors (spec §9 "suivi d'erreurs (back et app)"):
 * the app reports its uncaught errors here; they land in the server's
 * structured logs (channel `client`), where any error-tracking service
 * plugged on the logs picks them up. Open before sign-in, rate-limited,
 * fields whitelisted and truncated: no free-form personal data.
 */
final class ClientErrorController
{
    private const array FIELDS = ['message' => 500, 'stack' => 4000, 'route' => 200, 'appVersion' => 40, 'platform' => 20, 'requestId' => 64, 'kind' => 40];

    public function __construct(
        #[Autowire(service: 'monolog.logger.client')]
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.client_errors')]
        private readonly RateLimiterFactory $limiter,
    ) {
    }

    #[Route('/api/client-errors', name: 'client_errors', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            throw new ApiProblemException('request.rate_limited', 'Too many error reports.', 429);
        }

        $body = json_decode($request->getContent(), true);
        $context = [];
        foreach (self::FIELDS as $field => $max) {
            $value = \is_array($body) ? ($body[$field] ?? null) : null;
            if (\is_string($value) && '' !== $value) {
                $context[$field] = mb_substr($value, 0, $max);
            }
        }
        if (!isset($context['message'])) {
            throw new ApiProblemException('validation.invalid_body', 'An error message is required.', 422);
        }

        $this->logger->error('client.error', $context);

        return new Response(null, 204);
    }
}
