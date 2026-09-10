<?php

namespace App\Modules\Cliente\Http\Controllers;

use App\Modules\Cliente\Http\Requests\UpsertFichaSocioeconomicaRequest;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cliente\Models\FichaSocioeconomica;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Ficha socioeconómica de un cliente (1:1). Se rige por los permisos del
 * cliente (clientes.ver / clientes.editar) vía ClientePolicy. La consumen
 * los créditos hipotecarios para armar el documento del mismo nombre.
 */
class FichaSocioeconomicaController extends Controller
{
    use ApiResponse;

    public function show(Cliente $cliente): JsonResponse
    {
        Gate::authorize('view', $cliente);

        return $this->successResponse(
            $cliente->fichaSocioeconomica()->with('familiares')->first()
        );
    }

    public function update(UpsertFichaSocioeconomicaRequest $request, Cliente $cliente): JsonResponse
    {
        Gate::authorize('update', $cliente);

        $data = $request->validated();
        $familiares = Arr::pull($data, 'familiares', []);

        $ficha = DB::transaction(function () use ($cliente, $data, $familiares): FichaSocioeconomica {
            $ficha = FichaSocioeconomica::query()->updateOrCreate(
                ['cliente_id' => $cliente->id],
                [...$data, 'empresa_id' => $cliente->empresa_id],
            );

            // La lista de familiares se reemplaza completa en cada guardado.
            $ficha->familiares()->delete();

            if (! empty($familiares)) {
                $ficha->familiares()->createMany(
                    collect($familiares)->map(fn (array $f): array => [
                        'empresa_id' => $cliente->empresa_id,
                        'nombres' => $f['nombres'],
                        'edad' => $f['edad'] ?? null,
                        'parentesco' => $f['parentesco'] ?? null,
                        'estado_civil' => $f['estado_civil'] ?? null,
                        'ocupacion' => $f['ocupacion'] ?? null,
                    ])->all()
                );
            }

            return $ficha;
        });

        return $this->successResponse(
            $ficha->fresh('familiares'),
            'Ficha socioeconómica guardada'
        );
    }
}
