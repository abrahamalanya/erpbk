<?php

namespace App\Modules\Cliente\Services;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Usuario\Models\User;

final class ClienteService
{
    /**
     * Mantiene la agencia de los clientes asignados a $user igual a la suya
     * — un cliente hereda su agencia_id de quien lo registra/asigna, así que
     * si un administrador reasigna a este asesor a otra agencia después, sus
     * clientes (y los créditos que ya tenían, que apuntan a la agencia del
     * cliente en el momento de registrarse) se quedarían con la agencia
     * vieja para siempre — la visibilidad de un administrador_agencia
     * (acotada a su propia agencia_id) dejaría de coincidir con el asesor y
     * sus clientes. La llama UserController::updateUser() cada vez que
     * cambia la agencia de un usuario, igual que CajaService::sincronizarAgencia().
     *
     * Solo toca clientes (clientes.agencia_id); los créditos ya registrados
     * conservan la agencia que tenían al desembolsarse/registrarse — no se
     * reescribe su historial automáticamente.
     */
    public function sincronizarAgencia(User $user): void
    {
        Cliente::query()->where('asesor_id', $user->id)->update(['agencia_id' => $user->agencia_id]);
    }
}
