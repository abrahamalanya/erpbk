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
     * Invariantes de NEGOCIO, independientes del rol del actor. Corren
     * siempre — incluido cuando el actor es `sistemas`, a quien
     * UserPolicy::before() le da bypass total de autorización, así que estas
     * reglas son las únicas que le aplican a ese rol.
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

        if ($this->activaParaActor($actor) !== null) {
            throw new DomainException('Ya estás ingresando como otro usuario. Vuelve a tu cuenta antes de iniciar otra sesión.');
        }

        if ($this->activaParaTarget($target) !== null) {
            throw new DomainException('Este usuario ya está siendo impersonado en otra sesión.');
        }
    }

    /**
     * @return array{token: string, impersonacion: Impersonacion}
     */
    public function iniciar(User $actor, User $target, ?string $motivo, string $ip, string $userAgent): array
    {
        $this->autorizar($actor, $target);

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

    public function finalizar(Impersonacion $impersonacion): void
    {
        $impersonacion->update(['finalizado_at' => now()]);

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
