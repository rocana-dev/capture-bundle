<?php

namespace QaTracking\CaptureBundle\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Fingerprint of the browser that made a request: IP and user agent.
 *
 * A request's context is only handed to whoever presents the same fingerprint,
 * so a trace ID seen elsewhere (a HAR, the tracker itself) is not enough
 * on its own to request it.
 */
final class ClientFingerprint
{
    public static function of(Request $request): string
    {
        return hash('sha256', ($request->getClientIp() ?? '').'|'.$request->headers->get('User-Agent', ''));
    }
}
