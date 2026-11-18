<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Exception\ApiProblemException;
use App\Security\AuthTokenIssuer;
use App\Security\EmailSignIn;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sign-in without a password (spec §11 décision 68): ask for an email,
 * then exchange its code (typed, or carried by the link) for a long
 * session.
 */
final class EmailSignInController
{
    public function __construct(
        private readonly EmailSignIn $emailSignIn,
        private readonly AuthTokenIssuer $tokenIssuer,
    ) {
    }

    #[Route('/api/auth/email-sign-in', name: 'auth_email_sign_in_send', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        $body = self::body($request);
        $this->emailSignIn->send((string) ($body['email'] ?? ''), (string) ($body['locale'] ?? 'fr'));

        return new JsonResponse(['status' => 'sent']);
    }

    #[Route('/api/auth/email-sign-in/verify', name: 'auth_email_sign_in_verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        $body = self::body($request);
        $user = $this->emailSignIn->signIn(
            (string) ($body['email'] ?? ''),
            (string) ($body['code'] ?? ''),
            (string) ($body['locale'] ?? 'fr'),
            isset($body['id']) ? (string) $body['id'] : null,
        );

        return new JsonResponse($this->tokenIssuer->issue($user, longSession: true));
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(Request $request): array
    {
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            throw new ApiProblemException('validation.invalid_body', 'Malformed JSON body.', 400);
        }

        return $body;
    }
}
