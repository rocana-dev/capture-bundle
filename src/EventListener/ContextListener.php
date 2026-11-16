<?php

namespace QaTracking\CaptureBundle\EventListener;

use QaTracking\CaptureBundle\Collector\RequestContextCollector;
use QaTracking\CaptureBundle\Security\ClientFingerprint;
use QaTracking\CaptureBundle\Storage\ContextStore;
use QaTracking\CaptureBundle\Trace\TraceId;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Opens the context when each request starts and saves it when it finishes.
 *
 * When there is an exception it is saved immediately, in addition to at the end: a
 * fatal error or a time limit can prevent the request from finishing cleanly,
 * and those are exactly the cases most worth keeping.
 */
final class ContextListener
{
    public function __construct(
        private readonly RequestContextCollector $collector,
        private readonly ContextStore $store,
        private readonly bool $enabled,
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$this->enabled || !$event->isMainRequest()) {
            return;
        }

        try {
            $request = $event->getRequest();
            $traceId = $request->attributes->get(TraceListener::ATTRIBUTE);
            if (TraceId::isValid($traceId)) {
                $this->collector->start($traceId, $request, ClientFingerprint::of($request));
            }
        } catch (\Throwable) {
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if (!$this->enabled || !$this->collector->isActive()) {
            return;
        }

        try {
            $this->collector->setException($event->getThrowable());
            $this->save($event->getRequest(), null);
        } catch (\Throwable) {
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if (!$this->enabled || !$this->collector->isActive()) {
            return;
        }

        try {
            $this->save($event->getRequest(), $event->getResponse());
        } catch (\Throwable) {
        } finally {
            $this->collector->reset();
        }
    }

    private function save(Request $request, ?Response $response): void
    {
        $context = $this->collector->snapshot($request, $response, $this->currentUser());
        if (null !== $context) {
            $this->store->save($context);
        }
    }

    private function currentUser(): ?string
    {
        try {
            return $this->tokenStorage?->getToken()?->getUserIdentifier();
        } catch (\Throwable) {
            return null;
        }
    }
}
