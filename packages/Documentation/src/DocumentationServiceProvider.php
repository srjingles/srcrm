<?php

declare(strict_types=1);

namespace Relaticle\Documentation;

use App\Features\Documentation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;
use Relaticle\Documentation\Services\DocumentationService;

final class DocumentationServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/documentation.php', 'documentation');

        $this->app->singleton(DocumentationService::class);
    }

    /**
     * Bootstrap any application services.
     */
    /**
     * DIVERGENCIA CON UPSTREAM — ver docs/upstream-divergences.md.
     *
     * La feature apaga la SECCIÓN de documentación, o sea sus rutas. Vistas, componentes y
     * publishing se registran siempre: son inertes sin rutas que los usen, y condicionarlos
     * tenía dos efectos colaterales. Uno, `vendor:publish --tag=documentation-config` no
     * hacía nada con la feature apagada, en silencio. Y dos, el namespace 'documentation::'
     * quedaba sin declarar; larastan valida `view-string` llamando a `view()->exists()`
     * sobre la app arrancada, donde la feature evalúa a false, así que daba por inexistente
     * toda vista del paquete.
     */
    public function boot(): void
    {
        $this->registerViews();
        $this->registerComponents();
        $this->registerPublishing();

        if (! Feature::active(Documentation::class)) {
            return;
        }

        $this->registerRoutes();
    }

    /**
     * Register the package routes.
     */
    private function registerRoutes(): void
    {
        Route::middleware('web')
            ->group(function (): void {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });
    }

    /**
     * Register the package views.
     */
    private function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'documentation');
    }

    /**
     * Register Blade components.
     */
    private function registerComponents(): void
    {
        // Register components with the 'documentation::' namespace
        Blade::componentNamespace('Relaticle\\Documentation\\Components', 'documentation');

        // Register anonymous components
        $this->loadViewComponentsAs('documentation', []);
    }

    /**
     * Register publishable resources.
     */
    private function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            // Config
            $this->publishes([
                __DIR__.'/../config/documentation.php' => config_path('documentation.php'),
            ], 'documentation-config');

            // Views
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/documentation'),
            ], 'documentation-views');

            // Markdown
            $this->publishes([
                __DIR__.'/../resources/markdown' => resource_path('markdown/documentation'),
            ], 'documentation-markdown');
        }
    }
}
