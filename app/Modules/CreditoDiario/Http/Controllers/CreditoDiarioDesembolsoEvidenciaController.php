<?php

namespace App\Modules\CreditoDiario\Http\Controllers;

use App\Modules\Credito\Models\Credito;
use App\Modules\CreditoDiario\Http\Requests\StoreDesembolsoEvidenciaRequest;
use App\Modules\CreditoDiario\Models\CreditoDiarioDesembolsoEvidencia;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Evidencia obligatoria (video + GPS + fecha/hora) que un asesor debe
 * registrar antes de poder desembolsar un crédito diario — ver
 * CreditoService::desembolsar(), que exige esta evidencia solo para
 * tipo_credito === 'diario'. Misma autorización que el propio desembolso,
 * para que nadie pueda adjuntar evidencia a un crédito ajeno.
 */
class CreditoDiarioDesembolsoEvidenciaController extends Controller
{
    use ApiResponse;

    public function show(Credito $credito): JsonResponse
    {
        Gate::authorize('view', $credito);

        return $this->successResponse($credito->desembolsoEvidencia);
    }

    public function store(StoreDesembolsoEvidenciaRequest $request, Credito $credito): JsonResponse
    {
        Gate::authorize('desembolsar', $credito);

        abort_unless($credito->tipo_credito === 'diario', 404);

        if ($credito->estado !== 'aprobado') {
            throw new DomainException("El crédito debe estar en estado 'aprobado' para registrar la evidencia de desembolso (actual: '{$credito->estado}').");
        }

        $data = $request->validated();
        $archivoAnterior = $credito->desembolsoEvidencia?->video_path;

        $path = $request->file('video')->store("creditos-diarios/{$credito->id}/evidencias-desembolso", 'public');

        try {
            $recibidoEn = now();

            $evidencia = CreditoDiarioDesembolsoEvidencia::query()->updateOrCreate(
                ['credito_id' => $credito->id],
                [
                    'empresa_id' => $credito->empresa_id,
                    'agencia_id' => $credito->agencia_id,
                    'subido_por' => $request->user()->id,
                    'video_path' => $path,
                    'video_mime_type' => $request->file('video')->getMimeType(),
                    'video_size_bytes' => $request->file('video')->getSize(),
                    'latitud' => $data['latitud'],
                    'longitud' => $data['longitud'],
                    'precision_metros' => $data['precision_metros'] ?? null,
                    'capturado_en' => $data['capturado_en'] ?? $recibidoEn,
                    'recibido_en' => $recibidoEn,
                    'confirmado_en' => $recibidoEn,
                    'estado' => 'cargada',
                ]
            );
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($path);

            throw $e;
        }

        if ($archivoAnterior && $archivoAnterior !== $path) {
            Storage::disk('public')->delete($archivoAnterior);
        }

        return $this->successResponse($evidencia, 'Evidencia de desembolso registrada', 201);
    }
}
