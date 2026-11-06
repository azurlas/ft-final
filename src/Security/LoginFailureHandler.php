<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{JsonResponse,Request,Response};
use Symfony\Component\Security\Core\Exception\{AuthenticationException,TooManyLoginAttemptsAuthenticationException};
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Throttled logins answer 429 instead of the JWT bundle's generic 401, so clients and
 * monitoring can tell brute-force protection apart from a wrong password.
 */
final readonly class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(
        #[Autowire(service: 'lexik_jwt_authentication.handler.authentication_failure')]
        private AuthenticationFailureHandlerInterface $inner,
    ) {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if (!$exception instanceof TooManyLoginAttemptsAuthenticationException) {
            return $this->inner->onAuthenticationFailure($request, $exception);
        }

        return new JsonResponse(
            ['type' => 'about:blank', 'title' => 'Too many login attempts', 'status' => 429, 'detail' => 'Try again later'],
            429,
            ['Content-Type' => 'application/problem+json', 'Retry-After' => '60'],
        );
    }
}
