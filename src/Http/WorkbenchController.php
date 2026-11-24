<?php

declare(strict_types=1);

namespace Sendproof\Laravel\Http;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;
use Sendproof\Core\Capabilities;
use Sendproof\Core\Contracts\DescribesDatabaseSystem;
use Sendproof\Core\Protocol;
use Sendproof\Laravel\SendproofManager;

/** Serves the embedded workbench UI, discovery and observed-send traces under `/sendproof`. */
final class WorkbenchController
{
    public function __construct(
        private readonly SendproofManager $manager,
        private readonly Router $router,
        private readonly Application $app,
    ) {
    }

    public function index(Request $request): Response
    {
        if (!str_ends_with($request->getPathInfo(), '/')) {
            $query = (string) $request->server->get('QUERY_STRING', '');

            return new Response('', 302, ['Location' => $request->getBaseUrl() . '/sendproof/' . ($query === '' ? '' : '?' . $query)]);
        }

        return $this->asset('index.html');
    }

    public function asset(string $asset): Response
    {
        $bundle = $this->manager->ui();
        if (!$bundle->isAvailable()) {
            return $this->problem(503, 'The Sendproof UI bundle is not embedded. Run `composer embed-ui` in sendproof/core.');
        }
        $found = $bundle->asset($asset);
        if ($found === null) {
            return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Content-Type-Options' => 'nosniff']);
        }

        return new Response((string) file_get_contents($found->path), 200, [
            'Content-Type' => $found->contentType,
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function capabilities(Request $request): JsonResponse
    {
        $base = $request->getBaseUrl();
        $withBase = static fn (string $url): string => Capabilities::withBase($base, $url);
        $provider = $this->manager->previewProvider();
        $available = $provider !== null && $provider->isAvailable();
        // When every Preview is refused (a concurrent worker, a connection Sendproof can't guard), each
        // opted-in operation is listed as unavailable, with the reason.
        $concurrency = $available ? $this->manager->previewRefusal() : null;
        $operations = $available && $concurrency === null ? $this->operations() : [];
        $unavailable = $available ? $this->unavailable() : [];
        if ($available && $concurrency !== null) {
            $unavailable = [...array_map(static fn (array $operation): array => $operation + ['reason' => $concurrency], $this->operations()), ...$unavailable];
        }
        $openApi = $withBase((string) $this->manager->config('openapi_url', '/openapi/v1.json'));
        $apiName = $this->manager->config('api_name') ?? $this->app->make('config')->get('app.name', 'API');

        $capabilities = Capabilities::build(
            (string) $apiName,
            $this->app->environment(),
            $openApi,
            array_map(static fn (array $document): array => ['name' => $document['name'], 'url' => $withBase((string) $document['url'])], (array) $this->manager->config('documents', [])),
            array_map($withBase, (array) $this->manager->config('field_modules', [])),
            $available ? $provider->database() : null,
            $this->manager->identity($request),
            array_map(static fn (array $entry): array => [
                'label' => $entry['label'], 'kind' => $entry['kind'], 'url' => isset($entry['url']) ? $withBase((string) $entry['url']) : null,
            ], (array) $this->manager->config('auth_providers', [])),
            $operations,
            ['language' => 'php', 'framework' => 'Laravel'],
            array_map(static fn (\Sendproof\Core\Stores\DataStore $store): array => $store->describe(), $this->manager->dataStores()),
            $unavailable,
            $provider instanceof DescribesDatabaseSystem ? $provider->databaseSystem() : null,
        );

        $journeys = $this->manager->journeyFiles()->discovery($base);
        if ($journeys !== []) {
            $capabilities['journeys'] = $journeys;
        }

        return $this->json($capabilities);
    }

    public function journey(Request $request, string $file): Response
    {
        $json = $this->manager->journeyFiles()->json($file, $request->getBaseUrl());

        return $json === null ? $this->notFound() : new Response($json, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function trace(Request $request, string $id): JsonResponse|Response
    {
        $owner = $this->manager->ownerKey($request);
        if ($owner === null || !$this->manager->authorized($request)
            || !Protocol::isSameOrigin($request->headers->get('Sec-Fetch-Site'), $request->headers->all('origin'), $request->getSchemeAndHttpHost())) {
            return $this->notFound();
        }
        $trace = $this->manager->traces()->get($id, $owner);

        return $trace === null ? $this->notFound() : $this->json($trace);
    }

    /** @return list<array{method:string,path:string}> */
    private function operations(): array
    {
        $operations = [];
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (!PreviewMiddleware::isMarked($this->router, $route)) {
                continue;
            }
            $path = '/' . ltrim(preg_replace('/\{(\w+)\?\}/', '{$1}', $route->uri()), '/');
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $operations[$method . ' ' . $path] = ['method' => $method, 'path' => $path];
                }
            }
        }
        uasort($operations, static fn (array $a, array $b): int => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);

        return array_values($operations);
    }

    /**
     * Operations marked `preview: false` (`->sendproofPreview(false, 'reason')`), with the reason the UI shows.
     *
     * @return list<array{method:string,path:string,reason:string,stores?:list<string>}>
     */
    private function unavailable(): array
    {
        $operations = [];
        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            $mark = null;
            foreach ($this->router->gatherRouteMiddleware($route) as $middleware) {
                $mark ??= PreviewUnavailable::decode($middleware);
            }
            if ($mark === null || PreviewMiddleware::isMarked($this->router, $route)) {
                continue;
            }
            $path = '/' . ltrim(preg_replace('/\{(\w+)\?\}/', '{$1}', $route->uri()), '/');
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $operations[$method . ' ' . $path] = ['method' => $method, 'path' => $path, 'reason' => $mark['reason']] + (isset($mark['stores']) ? ['stores' => $mark['stores']] : []);
                }
            }
        }
        uasort($operations, static fn (array $a, array $b): int => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);

        return array_values($operations);
    }

    private function json(array $data): JsonResponse
    {
        $response = new JsonResponse($data, 200, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return $response;
    }

    private function notFound(): Response
    {
        return new Response('', 404, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function problem(int $status, string $title): JsonResponse
    {
        return new JsonResponse(['title' => $title, 'status' => $status], $status, ['Cache-Control' => 'no-store']);
    }
}
