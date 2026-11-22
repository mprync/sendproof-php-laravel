<?php

declare(strict_types=1);

namespace Sendproof\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Sendproof\Core\Preview\PreviewState;
use Sendproof\Laravel\SendproofManager;

/**
 * Route middleware that opts an operation in to Preview and Observed Send.
 *
 * Add it with `->sendproofPreview()` or the `sendproof.preview` alias, after authentication
 * and authorization middleware. It does nothing for ordinary requests. During a preview or an
 * observed send it marks the moment the request was admitted and the handler finished, which is
 * what lets the transport tell a real application result from an auth rejection.
 */
final class AllowPreview
{
    public const STATE = 'sendproof.state';
    public const MODE = 'sendproof.mode';

    public function __construct(private readonly SendproofManager $manager)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $state = $request->attributes->get(self::STATE);
        if (!$state instanceof PreviewState) {
            return $next($request);
        }

        $preview = $request->attributes->get(self::MODE) === 'preview';
        if ($state->reached || !$this->manager->authorized($request)) {
            // A denied or repeated entry must not run the handler; a preview then yields no envelope.
            return $preview ? new Response('', 403) : $next($request);
        }

        $state->reached = true;
        $state->owner = $preview ? null : $this->manager->ownerKey($request);
        $state->clock->startHandler();
        try {
            $response = $next($request);
        } finally {
            $state->clock->endHandler();
        }
        $state->completed = true;

        return $response;
    }
}
