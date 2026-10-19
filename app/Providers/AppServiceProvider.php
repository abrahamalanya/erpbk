<?php

namespace App\Providers;

use App\Modules\Credito\Tipos\CreditoTipoManager;
use App\Modules\CreditoDiario\Models\CreditoDiarioGarantia;
use App\Modules\CreditoDiario\Tipos\CreditoDiarioTipo;
use App\Modules\CreditoHipotecario\Models\Inmueble;
use App\Modules\CreditoHipotecario\Tipos\HipotecarioTipo;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Tipos\PrendarioTipo;
use App\Modules\CreditoVehicular\Models\Vehiculo;
use App\Modules\CreditoVehicular\Tipos\VehicularTipo;
use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Simulador\Models\SimulacionCredito;
use App\Modules\Sistemas\Models\PerfilAcceso;
use App\Modules\Sistemas\Policies\PerfilAccesoPolicy;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Scopes\PerfilComprobanteScope;
use App\Nucleo\Scopes\PerfilCreditoScope;
use App\Nucleo\Scopes\PerfilNotificacionScope;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CreditoTipoManager::class, function ($app): CreditoTipoManager {
            $manager = new CreditoTipoManager;
            $manager->registrar($app->make(PrendarioTipo::class));
            $manager->registrar($app->make(VehicularTipo::class));
            $manager->registrar($app->make(HipotecarioTipo::class));
            $manager->registrar($app->make(CreditoDiarioTipo::class));

            return $manager;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(PerfilAcceso::class, PerfilAccesoPolicy::class);
        Gate::define('gestionar-qr', fn (User $user): bool => $user->hasAnyRole(['sistemas', 'administrador_general']));

        Comprobante::addGlobalScope(new PerfilComprobanteScope);
        SimulacionCredito::addGlobalScope(new PerfilCreditoScope);
        DatabaseNotification::addGlobalScope(new PerfilNotificacionScope);

        /**
         * Stable aliases for the polymorphic garantía pivot (credito_garantia)
         * and garantia_fotos. Non-enforcing: models not listed here (e.g. the
         * User notifiable on notifications) keep persisting their FQCN.
         */
        Relation::morphMap([
            'bien' => Bien::class,
            'vehiculo' => Vehiculo::class,
            'inmueble' => Inmueble::class,
            'diario_garantia' => CreditoDiarioGarantia::class,
        ]);
    }
}
