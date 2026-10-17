<?php

namespace App\Modules\Sistemas\Services;

use App\Modules\Credito\Models\Credito;
use App\Modules\Sistemas\Events\NotificacionCreada;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Scopes\PerfilCreditoScope;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Sends a Notification to every recipient via the database channel only
 * (never Laravel's own "broadcast" channel — that one dispatches through
 * `Illuminate\Notifications\Events\BroadcastNotificationCreated`, which
 * implements ShouldBroadcast and therefore needs a queue worker to actually
 * deliver; this project has none) and fires NotificacionCreada
 * (ShouldBroadcastNow) ourselves right after, same pattern as every other
 * live update in this app.
 */
final class NotificacionService
{
    /**
     * @param  Collection<int, User>  $destinatarios
     */
    public function enviar(Collection $destinatarios, Notification $notification): void
    {
        $channel = new DatabaseChannel;

        foreach ($destinatarios as $destinatario) {
            $data = $notification->toArray($destinatario);
            if (isset($data['credito_id'])) {
                $tipo = Credito::withoutGlobalScope(PerfilCreditoScope::class)->whereKey($data['credito_id'])->value('tipo_credito');
                if ($tipo !== null && ! app(ModuloService::class)->perfilPermite($destinatario, $tipo)) {
                    continue;
                }
            }
            foreach (['billetaje_id' => 'billetajes', 'venta_id' => 'ventas', 'interes_id' => 'tienda_solicitudes'] as $key => $modulo) {
                if (isset($data[$key]) && ! app(ModuloService::class)->perfilPermite($destinatario, $modulo)) {
                    continue 2;
                }
            }

            // A fresh id per recipient — it's the notifications table's
            // primary key, reusing one across recipients would collide.
            $notification->id = (string) Str::uuid();

            $registro = $channel->send($destinatario, $notification);

            NotificacionCreada::dispatch($destinatario, $registro);
        }
    }
}
