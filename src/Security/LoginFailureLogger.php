<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/** Audit event for every failed authentication (login or bearer token). Passwords and tokens are never logged. */
#[AsEventListener]
final readonly class LoginFailureLogger
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        $passport = $event->getPassport();
        $login = null;
        if ($passport?->hasBadge(UserBadge::class)) {
            $login = $passport->getBadge(UserBadge::class)->getUserIdentifier();
        }
        $this->logger->warning('security.authentication_failed', [
            'request_id' => $request->attributes->get('_request_id'),
            'login' => $login !== null ? mb_substr($login, 0, 180) : null,
            'reason' => (new \ReflectionClass($event->getException()))->getShortName(),
            'client_ip' => $request->getClientIp(),
        ]);
    }
}
