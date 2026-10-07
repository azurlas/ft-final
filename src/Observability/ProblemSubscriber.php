<?php

declare(strict_types=1);

namespace App\Observability;

use Doctrine\DBAL\Exception as DatabaseException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\{AccessDeniedException,AuthenticationException};
use Symfony\Component\HttpFoundation\JsonResponse;
use Psr\Log\LoggerInterface;

final readonly class ProblemSubscriber implements EventSubscriberInterface
{
    public function __construct(private LoggerInterface $logger, private \Symfony\Bundle\SecurityBundle\Security $security)
    {
    }
    public static function getSubscribedEvents(): array
    {
        return ['kernel.exception' => ['respond',64]];
    }
    public function respond(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/') && !str_starts_with($event->getRequest()->getPathInfo(), '/internal/')) {
            return;
        }
        $e = $event->getThrowable();
        // Let the security firewall handle missing/invalid authentication.
        if ($e instanceof AuthenticationException || ($e instanceof AccessDeniedException && !$this->security->getUser())) {
            return;
        }
        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : ($e instanceof \Symfony\Component\Serializer\Exception\ExceptionInterface ? 400 : ($e instanceof AccessDeniedException ? 403 : ($e instanceof UniqueConstraintViolationException ? 409 : ($e instanceof DatabaseException || $e instanceof \Aws\Exception\AwsException ? 503 : 500))));
        $detail = $status >= 500 ? 'Service temporarily unavailable' : ($e instanceof HttpExceptionInterface ? $e->getMessage() : ($status === 400 ? 'Invalid request fields or values' : ($status === 403 ? 'Forbidden' : 'Resource conflict')));
        $this->logger->log($status >= 500 ? 'error' : 'notice', 'http.problem', ['status' => $status,'exception_class' => $e::class,'request_id' => $event->getRequest()->attributes->get('_request_id')]);
        $event->setResponse(new JsonResponse(['type' => 'about:blank','title' => $status >= 500 ? 'Service unavailable' : 'Request rejected','status' => $status,'detail' => $detail], $status, ['Content-Type' => 'application/problem+json']));
    }
}
