<?php

namespace App\Nucleo\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El disco 'public' arma cada URL de storage (foto de cliente, de garantías,
 * documentos...) a partir de APP_URL — un valor fijo que en desarrollo solo
 * resuelve en la máquina del backend (el *.test de Laravel Herd), no en el
 * celular/emulador que consume la API por otro host/puerto (artisan serve +
 * adb reverse, una IP de LAN). Esas URLs llegan inalcanzables al cliente
 * aunque el resto de la API funcione bien. Reescribir el 'url' del disco al
 * host real de cada request —antes de que cualquier controlador resuelva
 * Storage::disk('public')— evita el problema sin tocar cada modelo que
 * construye una URL de foto. No afecta a comandos/colas (no pasan por
 * middleware HTTP): ahí sigue usándose APP_URL, como siempre.
 */
class ResolveStorageUrlFromRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        config(['filesystems.disks.public.url' => $request->getSchemeAndHttpHost().'/storage']);

        return $next($request);
    }
}
