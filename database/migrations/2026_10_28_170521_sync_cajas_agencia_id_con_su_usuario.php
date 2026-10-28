<?php

use App\Modules\Caja\Models\Caja;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Backfill: `Caja::agencia_id` solo se copiaba de `users.agencia_id` al
     * CREAR la caja (CajaService::cajaDe()) y nunca se refrescaba después —
     * si un administrador reasignaba a alguien a otra agencia, su Caja se
     * quedaba con la agencia vieja para siempre (el selector de destinatario
     * de Billetajes, reportes de caja, etc. seguían mostrando la anterior).
     * Ahora que UserController::updateUser() la mantiene en sincronía hacia
     * adelante (ver CajaService::sincronizarAgencia()), esto corrige de una
     * vez las cajas que ya habían quedado desfasadas.
     */
    public function up(): void
    {
        Caja::query()->with('user')->get()->each(function (Caja $caja): void {
            if ($caja->user && $caja->agencia_id !== $caja->user->agencia_id) {
                $caja->update(['agencia_id' => $caja->user->agencia_id]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reversible: no se guardó qué agencia tenía antes cada caja desfasada.
    }
};
