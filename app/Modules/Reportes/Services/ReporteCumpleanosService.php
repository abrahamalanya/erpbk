<?php

namespace App\Modules\Reportes\Services;

use App\Modules\Usuario\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Reporte de cumpleaños de los USUARIOS del sistema (asesores, supervisores,
 * administradores) — no de clientes: se apoya en users.fecha_nacimiento.
 *
 * El rango desde/hasta acota el DÍA Y MES del cumpleaños dentro de un año, no
 * la fecha de nacimiento literal: los cumpleaños se repiten cada año, así que un
 * cliente que nació en 1985 aparece en el reporte de su cumpleaños de este año
 * igual que en el del año que viene. Por eso el rango se compara como clave
 * mes+día (101 para el 1 de enero, 1225 para el 25 de diciembre) y no como
 * fecha completa. Un rango que cruza el cambio de año (desde 20-12 hasta
 * 05-01) se resuelve como unión de los dos tramos en vez de devolver vacío.
 */
final class ReporteCumpleanosService
{
    /**
     * Usuarios que cumplen años dentro del rango indicado.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function cumpleanos(
        User $actor,
        string $desde,
        string $hasta,
        ?int $agenciaId,
        ?int $usuarioId,
    ): Collection {
        $inicio = Carbon::parse($desde)->startOfDay();
        $fin = Carbon::parse($hasta)->endOfDay();

        return $this->usuariosVisibles($actor, $agenciaId, $usuarioId)
            ->get()
            ->filter(fn (User $u): bool => $this->cumpleEnRango($u, $inicio, $fin))
            ->map(fn (User $u): array => $this->mapear($u))
            ->sortBy(['proximo_cumpleanos', 'nombre'])
            ->values();
    }

    /**
     * Mismo alcance que UserController::index(): el TenantScope ya confina a
     * la empresa del actor (salvo 'sistemas') y administrador_agencia queda
     * acotado a su agencia. Un filtro de agencia NO amplía ese alcance: si el
     * actor pide una agencia ajena, simplemente no ve filas.
     *
     * @return Builder<User>
     */
    private function usuariosVisibles(User $actor, ?int $agenciaId, ?int $usuarioId): Builder
    {
        return User::query()
            ->where('estado', 'activo')
            ->whereNotNull('fecha_nacimiento')
            ->when($actor->hasRole('administrador_agencia'), fn (Builder $q) => $q->where('agencia_id', $actor->agencia_id))
            ->when($agenciaId, fn (Builder $q) => $q->where('agencia_id', $agenciaId))
            ->when($usuarioId, fn (Builder $q) => $q->whereKey($usuarioId))
            ->with(['agencia', 'roles']);
    }

    /**
     * ¿El día/mes del cumpleaños cae dentro del rango? La clave mes+día permite
     * comparar el rango como dos números y resolver el cruce de año con dos
     * tramos, sin ensuciar el cálculo con fechas concretas.
     */
    private function cumpleEnRango(User $usuario, Carbon $inicio, Carbon $fin): bool
    {
        $claveUsuario = $this->claveMesDia($usuario->fecha_nacimiento);
        $claveDesde = $this->claveMesDia($inicio);
        $claveHasta = $this->claveMesDia($fin);

        if ($claveDesde <= $claveHasta) {
            return $claveUsuario >= $claveDesde && $claveUsuario <= $claveHasta;
        }

        // Rango cruzado de año: desde 20-12 hasta 05-01.
        return $claveUsuario >= $claveDesde || $claveUsuario <= $claveHasta;
    }

    private function claveMesDia(Carbon $fecha): int
    {
        return $fecha->month * 100 + $fecha->day;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapear(User $usuario): array
    {
        $proximo = $this->proximoCumpleanos($usuario->fecha_nacimiento);

        return [
            'id' => $usuario->id,
            'nombre' => trim("{$usuario->nombre} {$usuario->apellido}"),
            'dni' => $usuario->dni,
            'telefono' => $usuario->telefono,
            'fecha_nacimiento' => $usuario->fecha_nacimiento->toDateString(),
            'cumple_anios' => $this->edadQueCumple($usuario->fecha_nacimiento),
            'proximo_cumpleanos' => $proximo->toDateString(),
            'dias_para_cumple' => (int) now()->startOfDay()->diffInDays($proximo, false),
            'agencia' => $usuario->agencia?->nombre,
            'roles' => $usuario->getRoleNames()->all(),
        ];
    }

    /**
     * La edad que cumple en su próximo cumpleaños. Se ancla al próximo
     * cumpleaños (no al año en curso) para que un cumpleaños de enero listado en
     * diciembre muestre la edad que realmente va a cumplir.
     */
    private function edadQueCumple(Carbon $nacimiento): int
    {
        return $this->proximoCumpleanos($nacimiento)->year - $nacimiento->year;
    }

    /**
     * Próxima fecha en que ese día/mes vuelve a caer. Si el cumpleaños de este
     * año ya pasó, devuelve el del año siguiente.
     */
    private function proximoCumpleanos(Carbon $nacimiento): Carbon
    {
        $hoy = now()->startOfDay();

        foreach ([$hoy->year, $hoy->year + 1] as $anio) {
            $candidato = $this->cumpleanosEnAnio($nacimiento, $anio);

            if ($candidato->greaterThanOrEqualTo($hoy)) {
                return $candidato;
            }
        }

        return $this->cumpleanosEnAnio($nacimiento, $hoy->year + 2);
    }

    /**
     * El día/mes de nacimiento dentro de un año dado. El día se limita al
     * último día del mes destino porque un 29 de febrero cae el 28 en los años
     * no bisiestos y setDate() saltaría al mes siguiente.
     */
    private function cumpleanosEnAnio(Carbon $nacimiento, int $anio): Carbon
    {
        $primerDia = Carbon::create($anio, $nacimiento->month, 1);

        return $primerDia->copy()->setDate($anio, $nacimiento->month, min($nacimiento->day, $primerDia->daysInMonth));
    }
}
