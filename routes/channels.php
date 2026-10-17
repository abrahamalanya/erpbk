<?php

use App\Modules\Sistemas\Services\ModuloService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('empresa.{empresaId}.ubicaciones-asesores', function ($user, $empresaId) {
    return (int) $user->empresa_id === (int) $empresaId
        && app(ModuloService::class)->perfilPermite($user, 'ruta_cobranza')
        && $user->hasAnyRole(['administrador_general', 'administrador_agencia', 'supervisor']);
});

Broadcast::channel('sistemas.ubicaciones-asesores', function ($user) {
    return $user->hasRole('sistemas');
});
