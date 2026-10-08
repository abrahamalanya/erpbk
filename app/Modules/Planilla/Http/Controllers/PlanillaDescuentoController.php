<?php

namespace App\Modules\Planilla\Http\Controllers;

use App\Modules\Planilla\Http\Requests\StorePlanillaDescuentoRequest;
use App\Modules\Planilla\Models\PlanillaDescuento;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Adelantos, préstamos y descuentos contra el sueldo: se registran una vez y
 * cada planilla cerrada los consume hasta saldarlos.
 */
class PlanillaDescuentoController extends Controller
{
    use ApiResponse;

    /**
     * Descuentos visibles para el actor. Cada uno expone su saldo (lo que falta
     * pagar) y su cuota mensual, para que el frontend muestre "se le va a
     * descontar X este mes".
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PlanillaDescuento::class);

        $descuentos = PlanillaDescuento::query()
            ->with(['trabajador:id,nombre,apellido,dni', 'registradoPor'])
            ->when($request->integer('user_id'), fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($request->query('estado'), fn ($q, $estado) => $q->where('estado', $estado))
            ->when($request->user()->hasRole('administrador_agencia'), fn ($q) => $q->where('agencia_id', $request->user()->agencia_id))
            ->when($request->user()->hasAnyRole(['administrador_general', 'secretaria']), fn ($q) => $q->where('empresa_id', $request->user()->empresa_id))
            ->latest('fecha_registro')
            ->latest('id')
            ->get()
            ->map(fn (PlanillaDescuento $d): array => [
                'id' => $d->id,
                'user_id' => $d->user_id,
                'trabajador' => trim($d->trabajador?->nombre.' '.$d->trabajador?->apellido),
                'tipo' => $d->tipo,
                'tipo_label' => $d->tipoEnum()?->etiqueta(),
                'concepto' => $d->concepto,
                'monto' => $d->monto,
                'cuota' => $d->cuota,
                'pagado' => $d->pagado,
                'saldo' => $d->saldo,
                'cuota_del_mes' => $d->cuotaDelMes(),
                'fecha_registro' => $d->fecha_registro->toDateString(),
                'estado' => $d->estado,
            ]);

        return $this->successResponse($descuentos);
    }

    /**
     * Registra un adelanto/préstamo/descuento contra el sueldo de un
     * trabajador.
     */
    public function store(StorePlanillaDescuentoRequest $request): JsonResponse
    {
        Gate::authorize('create', PlanillaDescuento::class);

        $actor = $request->user();
        $monto = bcadd((string) $request->validated('monto'), '0', 2);

        $descuento = PlanillaDescuento::query()->create([
            'empresa_id' => $actor->empresa_id,
            'agencia_id' => $request->validated('agencia_id') !== null ? (int) $request->validated('agencia_id') : $actor->agencia_id,
            'user_id' => $request->validated('user_id'),
            'tipo' => $request->validated('tipo'),
            'concepto' => $request->validated('concepto'),
            'monto' => $monto,
            'cuota' => $request->validated('cuota'),
            'pagado' => '0.00',
            'saldo' => $monto,
            'fecha_registro' => $request->validated('fecha_registro'),
            'estado' => 'pendiente',
            'registrado_por' => $actor->id,
        ]);

        return $this->successResponse($descuento->load('trabajador'), 'Descuento registrado', 201);
    }

    /**
     * Anula un descuento: deja de descontarse del sueldo sin borrar el rastro.
     * Un descuento ya saldado no se anula —el dinero se cobró.
     */
    public function anular(Request $request, PlanillaDescuento $descuento): JsonResponse
    {
        Gate::authorize('anular', $descuento);

        if ($descuento->estado === 'saldado') {
            return $this->errorResponse('No se puede anular un descuento ya saldado', 422);
        }

        $descuento->update(['estado' => 'anulado']);

        return $this->successResponse($descuento, 'Descuento anulado');
    }
}
