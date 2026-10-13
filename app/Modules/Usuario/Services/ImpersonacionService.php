<?php

namespace App\Modules\Usuario\Services;

use App\Modules\Usuario\Models\Impersonacion;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Scopes\TenantScope;
use DomainException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * "Ingresar como": emite un token Sanctum nuevo cuyo dueño es el usuario
 * impersonado (así `Auth::user()` y el TenantScope se resuelven solos en
 * cada request posterior, ver UserPolicy::impersonar()) y lleva la bitácora
 * en `impersonaciones`.
 */
final class ImpersonacionService
{
    /**
     * Invariantes de NEGOCIO que SÍ bloquean, independientes del rol del
     * actor. Corren siempre — incluido cuando el actor es `sistemas`, a
     * quien UserPolicy::before() le da bypass total de autorización, así
     * que estas reglas son las únicas que le aplican a ese rol.
     */
    public function autorizar(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            throw new DomainException('No puedes ingresar como tú mismo.');
        }

        if ($target->hasRole('sistemas')) {
            throw new DomainException('No se puede ingresar como un usuario con rol sistemas.');
        }

        if ($target->estado !== 'activo') {
            throw new DomainException('No se puede ingresar como un usuario inactivo.');
        }
    }

    /**
     * Cierra cualquier impersonación previa que choque con la que está por
     * empezar (el actor ya tenía una abierta, o el target ya estaba siendo
     * impersonado por otra sesión) en vez de bloquear con 422.
     *
     * Antes esto lanzaba DomainException, pero una impersonación "colgada"
     * (app cerrada a la fuerza a mitad de sesión, sin pasar por
     * finalizar()/logout()) dejaba al admin trabado para siempre sin ninguna
     * forma de recuperarse — el token de esa sesión vieja ya no existe en
     * ningún dispositivo suyo. Cerrar la vieja aquí es seguro: esta llamada
     * solo se alcanza con el token REAL y vigente del actor (nunca con el
     * token de una impersonación — ver UserPolicy::impersonar(), que ya
     * exige el permiso `usuarios.impersonar`, algo que un usuario
     * impersonado de rol inferior nunca tiene), así que no es una vía para
     * escalar privilegios, solo limpieza de estado abandonado.
     */
    private function supersedirConflictos(User $actor, User $target): void
    {
        if ($previa = $this->activaParaActor($actor)) {
            $this->finalizar($previa, 'Cerrada automáticamente: el actor inició una nueva impersonación.');
        }

        if ($previa = $this->activaParaTarget($target)) {
            $this->finalizar($previa, 'Cerrada automáticamente: otro admin empezó a impersonar a este mismo usuario.');
        }
    }

    /**
     * @return array{token: string, impersonacion: Impersonacion}
     */
    public function iniciar(User $actor, User $target, ?string $motivo, string $ip, string $userAgent): array
    {
        $this->autorizar($actor, $target);
        $this->supersedirConflictos($actor, $target);

        $token = $target->createToken("impersonacion:{$target->id}");

        $impersonacion = Impersonacion::query()->create([
            // Siempre la empresa del IMPERSONADO, nunca la del actor: si el
            // actor es `sistemas` (empresa_id normalmente null) y guardáramos
            // la suya, activaParaToken()/finalizar() —que corren con
            // Auth::user() = target— nunca encontrarían la fila.
            'empresa_id' => $target->empresa_id,
            'iniciado_por' => $actor->id,
            'impersonado_id' => $target->id,
            'token_id' => $token->accessToken->id,
            'motivo' => $motivo,
            'ip' => $ip,
            'user_agent' => $userAgent,
        ]);

        return [
            'token' => $token->plainTextToken,
            'impersonacion' => $impersonacion,
        ];
    }

    public function finalizar(Impersonacion $impersonacion, ?string $notaAutomatica = null): void
    {
        $impersonacion->update([
            'finalizado_at' => now(),
            'motivo' => $notaAutomatica !== null
                ? trim(($impersonacion->motivo !== null ? $impersonacion->motivo.' — ' : '').$notaAutomatica)
                : $impersonacion->motivo,
        ]);

        PersonalAccessToken::find($impersonacion->token_id)?->delete();
    }

    /**
     * El `token_id` ya identifica una sola fila sin ambigüedad, y esto se
     * consulta con `Auth::user()` = el usuario IMPERSONADO (el dueño del
     * token actual), no el actor — así que el TenantScope de `empresa_id`
     * aquí es redundante y solo puede romper el lookup, nunca ayudarlo.
     */
    public function activaParaToken(?int $tokenId): ?Impersonacion
    {
        if ($tokenId === null) {
            return null;
        }

        return Impersonacion::query()
            ->withoutGlobalScope(TenantScope::class)
            ->activas()
            ->where('token_id', $tokenId)
            ->first();
    }

    public function activaParaActor(User $actor): ?Impersonacion
    {
        return Impersonacion::query()
            ->withoutGlobalScope(TenantScope::class)
            ->activas()
            ->where('iniciado_por', $actor->id)
            ->first();
    }

    public function activaParaTarget(User $target): ?Impersonacion
    {
        return Impersonacion::query()
            ->withoutGlobalScope(TenantScope::class)
            ->activas()
            ->where('impersonado_id', $target->id)
            ->first();
    }
}
