<?php

declare(strict_types=1);

namespace App\Observability;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\{RequestEvent,ResponseEvent};

final readonly class RequestLoggingSubscriber implements EventSubscriberInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }
    public static function getSubscribedEvents(): array
    {
        return ['kernel.request' => ['start',100],'kernel.response' => ['finish',-100]];
    }
    public function start(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        } $r = $event->getRequest();
        $id = $r->headers->get('X-Request-ID', '');
        if (!preg_match('/^[a-zA-Z0-9-]{8,64}$/', $id)) {
            $id = bin2hex(random_bytes(16));
        } $r->attributes->set('_request_id', $id);
        $r->attributes->set('_started', microtime(true));
    }
    public function finish(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        } $r = $event->getRequest();
        $id = $r->attributes->get('_request_id');
        $event->getResponse()->headers->set('X-Request-ID', $id);
        $this->logger->info('http.completed', ['request_id' => $id,'method' => $r->getMethod(),'route' => $r->attributes->get('_route'),'status' => $event->getResponse()->getStatusCode(),'duration_ms' => round((microtime(true) - $r->attributes->get('_started', microtime(true))) * 1000, 2)]);
    }
}
