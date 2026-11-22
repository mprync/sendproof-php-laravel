<?php

declare(strict_types=1);

namespace Sendproof\Laravel\Http;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Sendproof\Core\Preview\CapturedResponse;
use Sendproof\Core\Preview\ObservedRunner;
use Sendproof\Core\Preview\PreviewResult;
use Sendproof\Core\Preview\PreviewRunner;
use Sendproof\Core\Preview\PreviewState;
use Sendproof\Core\Protocol;
use Sendproof\Laravel\SendproofManager;
use Throwable;

/**
 * Global middleware that implements the Preview and Observed Send transport.
 *
 * A preview request `/sendproof/_preview/items/12` is rewritten to `/items/12` and dispatched through
 * the rest of the application's real pipeline in the same process. Routing, authentication,
 * validation and the handler therefore behave exactly as for a normal request. The result is
 * buffered, the database transaction is rolled back, and only then is a confirmed envelope built.
 */
final class PreviewMiddleware
{
    /** Exception types that are ordinary application outcomes rather than failures. */
    private const HANDLED = [
        HttpExceptionInterface::class, ValidationException::class, AuthenticationException::class,
        AuthorizationException::class, ModelNotFoundException::class, TokenMismatchException::class,
    ];

    /** How many requests this process is handling inside one another; 0 between requests. */
    private static int $depth = 0;

    public function __construct(
        private readonly Application $app,
        private readonly Router $router,
        private readonly SendproofManager $manager,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        // A worker that keeps the application (Octane, RoadRunner, FrankenPHP) can hand this request
        // the recorder of the last one. Its Preview scope, which refuses late writes for the rest of
        // that request, must not refuse this request's writes, so every top-level request starts
        // clean. A request dispatched inside another (a Preview's handler calling the kernel) keeps
        // the scope it runs in.
        $recorder = $this->manager->recorder();
        if (self::$depth === 0 && !$recorder->isCapturing()) {
            $recorder->forget();
        }
        self::$depth++;
        try {
            return $this->route($request, $next, $started);
        } finally {
            self::$depth--;
        }
    }

    private function route(Request $request, Closure $next, int $started): Response
    {
        $path = $request->getPathInfo();
        $prefer = $request->headers->all('prefer');
        $offset = Protocol::reservedOffset($path);

        if ($offset === null) {
            if ($this->manager->enabled() && !$request->isMethod('HEAD') && Protocol::requestsTrace($prefer) && $this->sameOrigin($request)) {
                $observed = $this->observe($request, $next, $started);
                if ($observed !== null) {
                    return $observed;
                }
            }

            return Protocol::requestsRollback($prefer)
                ? $this->problem(400, 'Use the Sendproof preview URL for rollback requests.')
                : $next($request);
        }

        if (!$this->manager->enabled()) {
            return $this->problem(404, 'Sendproof previews are unavailable.');
        }
        if (!Protocol::hasExactPreference($prefer)) {
            return $this->problem(400, 'A Sendproof preview requires Prefer: tx=rollback.');
        }
        if ($request->isMethod('HEAD')) {
            return $this->problem(405, 'A preview requires a response body.');
        }

        $target = substr($path, $offset + strlen(Protocol::PREFIX));
        if (!Protocol::isValidTarget($target)) {
            return $this->problem(400, 'The Sendproof preview target is invalid.');
        }

        $inner = $this->rewrite($request, substr($path, 0, $offset) . $target);
        try {
            $route = $this->router->getRoutes()->match($inner);
        } catch (MethodNotAllowedHttpException) {
            return $this->problem(405, 'The preview target was not found for this method.');
        } catch (NotFoundHttpException) {
            return $this->problem(404, 'The preview target was not found for this method.');
        }
        if (!self::isMarked($this->router, $route)) {
            return $this->problem(409, 'This operation is unavailable for preview.');
        }
        if (($reason = $this->manager->previewRefusal()) !== null) {
            // Nothing runs: refused before the handler, so no write of this or any other request is touched.
            return $this->problem(409, $reason);
        }
        if (!$this->sameOrigin($request)) {
            return $this->problem(403, 'This preview is forbidden.');
        }
        $provider = $this->manager->previewProvider();
        if ($provider === null || !$provider->isAvailable()) {
            return $this->problem(503, 'Preview requires one available preview provider.');
        }

        $state = new PreviewState($started);
        $inner->attributes->set(AllowPreview::STATE, $state);
        $inner->attributes->set(AllowPreview::MODE, 'preview');
        $runner = new PreviewRunner($this->manager->recorder(), $this->reporter());
        $result = $runner->run($provider, $state, fn (): CapturedResponse => $this->dispatch($request, $inner, $next), $inner, $this->manager->dataStores());

        return $this->respond($result);
    }

    /** Whether a route opted in with the AllowPreview middleware. */
    public static function isMarked(Router $router, Route $route): bool
    {
        foreach ($router->gatherRouteMiddleware($route) as $middleware) {
            if (is_string($middleware) && explode(':', $middleware, 2)[0] === AllowPreview::class) {
                return true;
            }
        }

        return false;
    }

    /** Sends an ordinary request with tracing when its route opted in; null leaves it untouched. */
    private function observe(Request $request, Closure $next, int $started): ?Response
    {
        try {
            $route = $this->router->getRoutes()->match($request);
        } catch (Throwable) {
            return null;
        }
        $provider = $this->manager->previewProvider();
        if (!self::isMarked($this->router, $route) || !in_array($request->getMethod(), $route->methods(), true)
            || $provider === null || !$provider->isAvailable()) {
            return null;
        }

        $state = new PreviewState($started);
        $request->attributes->set(AllowPreview::STATE, $state);
        $request->attributes->set(AllowPreview::MODE, 'observe');
        $runner = new ObservedRunner($this->manager->recorder(), $this->manager->traces(), $this->reporter());
        [$response, $headers] = $runner->run($provider, $state, function () use ($next, $request, $state): Response {
            $response = $next($request);
            if (self::failure($response) !== null) {
                $state->completed = false;
            }

            return $response;
        }, $request, fn (): ?string => $this->manager->ownerKey($request));
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /** Builds the in-process request for the preview target, keeping method, headers, query and body. */
    private function rewrite(Request $request, string $path): Request
    {
        $request->getContent();
        $query = (string) $request->server->get('QUERY_STRING', '');
        $server = array_merge($request->server->all(), [
            'REQUEST_URI' => $request->getBaseUrl() . $path . ($query === '' ? '' : '?' . $query),
        ]);

        return $request->duplicate(null, null, null, null, null, $server);
    }

    /** Runs the rewritten request through the remaining global middleware and the router. */
    private function dispatch(Request $original, Request $inner, Closure $next): CapturedResponse
    {
        Facade::clearResolvedInstance('request');
        try {
            $response = $next($inner);
        } finally {
            $this->app->instance('request', $original);
            Facade::clearResolvedInstance('request');
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse || $response->getContent() === false) {
            throw new \RuntimeException('Sendproof previews require a buffered response.');
        }

        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            $headers[(string) $name] = implode(', ', (array) $values);
        }

        return new CapturedResponse($response->getStatusCode(), $headers, (string) $response->getContent(), self::failure($response));
    }

    /** The unhandled error that the framework rendered into this response, if any. */
    private static function failure(Response $response): ?Throwable
    {
        $exception = property_exists($response, 'exception') ? $response->exception : null;

        return $exception instanceof Throwable && !self::isHandled($exception) ? $exception : null;
    }

    private static function isHandled(Throwable $exception): bool
    {
        foreach (self::HANDLED as $type) {
            if ($exception instanceof $type) {
                return true;
            }
        }

        return false;
    }

    private function respond(PreviewResult $result): Response
    {
        if ($result->blocked !== null) {
            // The write guard stopped the Preview: 409 problem+json, never a confirmation.
            $response = new JsonResponse($result->problem(), 409, ['Cache-Control' => 'no-store']);
            $response->headers->set('Content-Type', 'application/problem+json');

            return $response;
        }
        if (!$result->isConfirmed()) {
            return $this->problem($result->status, $result->title, $result->headers);
        }

        $response = new JsonResponse($result->envelope, 200, [], 0);
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Preference-Applied', Protocol::ROLLBACK_PREFERENCE);
        $response->headers->set('Server-Timing', (string) $result->timing);

        return $response;
    }

    /** @param array<string,string> $headers */
    private function problem(int $status, string $title, array $headers = []): Response
    {
        $response = new JsonResponse(['title' => $title, 'status' => $status], $status, $headers + ['Cache-Control' => 'no-store']);
        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }

    private function sameOrigin(Request $request): bool
    {
        return Protocol::isSameOrigin(
            $request->headers->get('Sec-Fetch-Site'),
            $request->headers->all('origin'),
            $request->getSchemeAndHttpHost(),
        );
    }

    /** @return callable(Throwable): void */
    private function reporter(): callable
    {
        return function (Throwable $error): void {
            if ($this->app->bound(LoggerInterface::class)) {
                $this->app->make(LoggerInterface::class)->error('A Sendproof preview failed: ' . $error->getMessage(), ['exception' => $error]);
            }
        };
    }
}
