<?php

namespace App\Modules\Tienda\Services;

use App\Modules\CreditoHipotecario\Models\Inmueble;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\CreditoVehicular\Models\Vehiculo;
use App\Modules\Tienda\Models\InteresArticulo;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Public storefront logic shared by every garantía type. A garantía in
 * estado "disponible_venta" (its crédito went "en_venta") is a storefront
 * articulo; only fields safe to show a stranger are published.
 */
final class TiendaService
{
    /**
     * The garantía models the storefront lists, keyed by morph alias.
     *
     * @var array<string, class-string<Model>>
     */
    private const ARTICULOS = [
        'bien' => Bien::class,
        'vehiculo' => Vehiculo::class,
        'inmueble' => Inmueble::class,
    ];

    /**
     * Unified listing across every garantía type, newest first. Volumes are
     * small (only rematados), so it merges each type's rows in PHP rather
     * than building a cross-table UNION.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function listar(?string $tipo, ?int $empresaId, ?int $agenciaId, int $perPage = 12, int $page = 1): LengthAwarePaginator
    {
        $modelos = $tipo && isset(self::ARTICULOS[$tipo])
            ? [self::ARTICULOS[$tipo]]
            : array_values(self::ARTICULOS);

        $articulos = collect($modelos)->flatMap(function (string $modelo) use ($empresaId, $agenciaId): Collection {
            $query = $modelo::query()
                ->where('estado', 'disponible_venta')
                ->with(['fotos', 'agencia:id,empresa_id,nombre', 'empresa:id,nombre']);

            if ($empresaId !== null) {
                $query->where('empresa_id', $empresaId);
            }

            if ($agenciaId !== null) {
                $query->where('agencia_id', $agenciaId);
            }

            return $query->get();
        })->sortByDesc('created_at')->values();

        $slice = $articulos->forPage($page, $perPage)->map(fn (Model $a): array => $this->datosPublicos($a))->values();

        return new LengthAwarePaginator($slice, $articulos->count(), $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]);
    }

    /**
     * @param  class-string<Model>|null  $modelo  restrict to this garantía model
     */
    public function resolver(string $tipo, int $id): ?Model
    {
        $modelo = self::ARTICULOS[$tipo] ?? null;

        if ($modelo === null) {
            return null;
        }

        return $modelo::query()
            ->where('estado', 'disponible_venta')
            ->with(['fotos', 'agencia:id,empresa_id,nombre', 'empresa:id,nombre'])
            ->find($id);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function registrarInteres(Model $articulo, array $datos): InteresArticulo
    {
        return InteresArticulo::query()->create([
            'articulo_type' => $articulo->getMorphClass(),
            'articulo_id' => $articulo->id,
            'empresa_id' => $articulo->empresa_id,
            'agencia_id' => $articulo->agencia_id,
            ...$datos,
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    public function controladoresDe(Model $articulo): Collection
    {
        return User::role('administrador_general')->where('empresa_id', $articulo->empresa_id)->get()
            ->merge(User::role('administrador_agencia')->where('agencia_id', $articulo->agencia_id)->get());
    }

    /**
     * @return array<string, mixed>
     */
    public function datosPublicos(Model $articulo): array
    {
        $base = [
            'id' => $articulo->id,
            'articulo_tipo' => $articulo->getMorphClass(),
            'nombre' => $articulo->nombre,
            'marca' => $articulo->marca,
            'modelo' => $articulo->modelo,
            'valorizacion' => $articulo->valorizacion,
            'precio_venta' => $articulo->precio_venta,
            'puntaje' => $articulo->puntaje,
            'foto_cliente_producto_url' => $articulo->foto_cliente_producto_url,
            'video_url' => $articulo->video_url,
            'fotos' => $articulo->fotos->map(fn ($foto): array => ['id' => $foto->id, 'url' => $foto->url, 'orden' => $foto->orden])->values(),
            'agencia' => $articulo->agencia ? ['id' => $articulo->agencia->id, 'nombre' => $articulo->agencia->nombre] : null,
            'empresa' => $articulo->empresa ? ['id' => $articulo->empresa->id, 'nombre' => $articulo->empresa->nombre] : null,
        ];

        return match ($articulo->getMorphClass()) {
            'vehiculo' => [
                ...$base,
                'tipo' => 'vehiculo',
                'placa' => $articulo->placa,
                'anio' => $articulo->anio,
                'color' => $articulo->color,
                'clase' => $articulo->clase,
                'tiene_soat' => $articulo->tiene_soat,
            ],
            'inmueble' => [
                ...$base,
                'tipo' => 'inmueble',
                'tipo_inmueble' => $articulo->tipo_inmueble,
                'direccion' => $articulo->direccion,
                'distrito' => $articulo->distrito,
                'provincia' => $articulo->provincia,
                'departamento' => $articulo->departamento,
                'area_terreno' => $articulo->area_terreno,
                'area_construida' => $articulo->area_construida,
            ],
            default => [
                ...$base,
                'tipo' => $articulo->tipo,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function tiposDisponibles(): array
    {
        return array_keys(self::ARTICULOS);
    }
}
