<?php

namespace App\Providers;

use App\Modules\Credito\Tipos\CreditoTipoManager;
use App\Modules\CreditoHipotecario\Models\Inmueble;
use App\Modules\CreditoHipotecario\Tipos\HipotecarioTipo;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoPrendario\Tipos\PrendarioTipo;
use App\Modules\CreditoVehicular\Models\Vehiculo;
use App\Modules\CreditoVehicular\Tipos\VehicularTipo;
use Illuminate\Database\Eloquent\Relations\Relation;
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

            return $manager;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * Stable aliases for the polymorphic garantía pivot (credito_garantia)
         * and garantia_fotos. Non-enforcing: models not listed here (e.g. the
         * User notifiable on notifications) keep persisting their FQCN.
         */
        Relation::morphMap([
            'bien' => Bien::class,
            'vehiculo' => Vehiculo::class,
            'inmueble' => Inmueble::class,
        ]);
    }
}
