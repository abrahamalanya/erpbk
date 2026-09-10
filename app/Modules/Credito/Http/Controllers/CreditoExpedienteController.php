<?php

namespace App\Modules\Credito\Http\Controllers;

use App\Modules\Credito\Http\Requests\GuardarExpedienteDocumentoRequest;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CreditoExpedienteDocumento;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Fotos / capturas del expediente de un crédito hipotecario (por rol y
 * sección). El documento "expediente" las embebe al renderizar el PDF.
 */
class CreditoExpedienteController extends Controller
{
    use ApiResponse;

    public function index(Credito $credito): JsonResponse
    {
        Gate::authorize('view', $credito);

        return $this->successResponse($credito->expedienteDocumentos()->with('subidoPor')->get());
    }

    public function store(GuardarExpedienteDocumentoRequest $request, Credito $credito): JsonResponse
    {
        Gate::authorize('view', $credito);

        $data = $request->validated();
        $creadas = [];

        foreach ($request->file('archivos') as $archivo) {
            $creadas[] = CreditoExpedienteDocumento::query()->create([
                'credito_id' => $credito->id,
                'empresa_id' => $credito->empresa_id,
                'subido_por' => $request->user()->id,
                'rol' => $data['rol'],
                'seccion' => $data['seccion'],
                'path' => $archivo->store("credito-expediente/{$credito->id}", 'public'),
            ]);
        }

        return $this->successResponse(collect($creadas), 'Imágenes agregadas al expediente', 201);
    }

    public function destroy(Credito $credito, CreditoExpedienteDocumento $documento): JsonResponse
    {
        Gate::authorize('view', $credito);

        abort_unless($documento->credito_id === $credito->id, 404);

        if ($documento->path) {
            Storage::disk('public')->delete($documento->path);
        }

        $documento->delete();

        return $this->successResponse(null, 'Imagen eliminada del expediente');
    }
}
