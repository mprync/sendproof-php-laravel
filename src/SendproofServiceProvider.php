<?php

declare(strict_types=1);

namespace Sendproof\Laravel;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Routing\Router;
use Illuminate\Routing\RouteRegistrar;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Sendproof\Core\Capture\Recorder;
use Sendproof\Core\JourneyFiles;
use Sendproof\Laravel\Capture\CaptureListeners;
use Sendproof\Laravel\Eloquent\ChangeCollector;
use Sendproof\Laravel\Eloquent\EloquentPreviewProvider;
use Sendproof\Laravel\Http\AllowPreview;
use Sendproof\Laravel\Http\AuthorizeWorkbench;
use Sendproof\Laravel\Http\PreviewMiddleware;
use Sendproof\Laravel\Http\PreviewUnavailable;
use Sendproof\Laravel\Http\WorkbenchController;

/**
 * Registers Sendproof. The package installs the preview transport as global middleware, but
 * mounts the workbench routes and accepts previews only when enabled in an allow-listed environment.
 */
final class SendproofServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sendproof.php', 'sendproof');
        $this->app->singleton(SendproofManager::class);
        $this->app->scoped(Recorder::class);
        $this->app->scoped(ChangeCollector::class);
        $this->app->bind(EloquentPreviewProvider::class, fn ($app) => new EloquentPreviewProvider(
            $app->make('db'),
            $app->make(ChangeCollector::class),
            (array) $app['config']->get('sendproof.connections', [null]),
            $app['config']->get('sendproof.database_label'),
            fn (): Recorder => $app->make(Recorder::class),
            (array) $app['config']->get('sendproof.safe_procedures', []),
        ));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/sendproof.php' => $this->app->configPath('sendproof.php')], 'sendproof-config');

        $this->app->make('router')->aliasMiddleware('sendproof.preview', AllowPreview::class);
        $this->registerMacros();
        (new CaptureListeners($this->app))->register($this->app->make('events'));

        $kernel = $this->app->make(Kernel::class);
        if (method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(PreviewMiddleware::class);
        }

        $manager = $this->app->make(SendproofManager::class);
        if ($manager->enabled()) {
            // Only an enabled environment touches a data store client.
            $manager->hookDataStores();
            if (!$this->app->routesAreCached()) {
                $this->mountRoutes();
            }
        }
    }

    /**
     * Adds `->sendproofPreview()` to routes and route groups. `->sendproofPreview(false, 'Clears the
     * Redis session')` marks the operation `preview: false` instead: the workbench shows the reason
     * before anyone tries and a Preview of it is refused without running the handler. Give a
     * reason, the `stores` it writes, or both.
     */
    private function registerMacros(): void
    {
        RouteDefinition::macro('sendproofPreview', function (bool $enabled = true, ?string $reason = null, array $stores = []) {
            /** @var RouteDefinition $this */
            return $this->middleware($enabled ? AllowPreview::class : PreviewUnavailable::using($reason, $stores));
        });
        RouteRegistrar::macro('sendproofPreview', function (bool $enabled = true, ?string $reason = null, array $stores = []) {
            /** @var RouteRegistrar $this */
            return $this->middleware($enabled ? AllowPreview::class : PreviewUnavailable::using($reason, $stores));
        });
    }

    private function mountRoutes(): void
    {
        $router = $this->app->make(Router::class);
        $middleware = $this->app['config']->get('sendproof.middleware');
        $middleware = (array) ($middleware ?? ($router->hasMiddlewareGroup('web') ? ['web'] : []));
        // Applies the gate ability and any authorizeUsing() closure to every workbench route.
        $middleware[] = AuthorizeWorkbench::class;

        Route::middleware($middleware)->prefix('sendproof')->group(function (): void {
            Route::get('/', [WorkbenchController::class, 'index']);
            Route::get('signin.html', [WorkbenchController::class, 'index']);
            Route::get('_api/capabilities', [WorkbenchController::class, 'capabilities']);
            Route::get('_api/traces/{id}', [WorkbenchController::class, 'trace'])->where('id', '[0-9a-fA-F-]{32,36}');
            Route::get('journeys/{file}', [WorkbenchController::class, 'journey'])->where('file', JourneyFiles::ROUTE_FILE);
            Route::get('{asset}', [WorkbenchController::class, 'asset'])->where('asset', '.*');
        });
    }
}
