<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Override;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    #[Override]
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     *
     * Horizon (el dashboard de colas) es una superficie de administración del
     * sistema, así que se autoriza a cualquier SystemAdministrator autenticado
     * (el modelo/guard del panel sysadmin), no a los usuarios del CRM. El guard
     * 'web' que Horizon consulta por defecto va vacío en el dominio del sysadmin,
     * por eso resolvemos el guard 'sysadmin' explícitamente; el parámetro es
     * nullable para que el gate se evalúe aunque no haya usuario 'web'.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?Authenticatable $user = null): bool => auth('sysadmin')->user() instanceof SystemAdministrator);
    }
}
