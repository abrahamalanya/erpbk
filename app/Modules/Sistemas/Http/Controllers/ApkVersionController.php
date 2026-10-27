<?php

namespace App\Modules\Sistemas\Http\Controllers;

use App\Modules\Sistemas\Http\Requests\StoreApkVersionRequest;
use App\Modules\Sistemas\Models\ApkVersion;
use App\Modules\Sistemas\Services\ApkVersionService;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApkVersionController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ApkVersionService $apkVersions) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', ApkVersion::class);

        $versiones = ApkVersion::query()
            ->where('application_id', config('apk_updates.application_id'))
            ->with('subidoPor')
            ->latest('build_number')
            ->paginate(20);

        return $this->successResponse($versiones);
    }

    public function store(StoreApkVersionRequest $request): JsonResponse
    {
        Gate::authorize('create', ApkVersion::class);

        $apkVersion = $this->apkVersions->subir($request->user(), $request->validated(), $request->file('apk'));

        return $this->successResponse($apkVersion, 'Versión registrada', 201);
    }

    public function publicar(ApkVersion $apkVersion): JsonResponse
    {
        Gate::authorize('publicar', ApkVersion::class);

        $apkVersion = $this->apkVersions->publicar($apkVersion);

        return $this->successResponse($apkVersion, 'Versión publicada como vigente');
    }

    public function destroy(ApkVersion $apkVersion): JsonResponse
    {
        Gate::authorize('delete', ApkVersion::class);

        $this->apkVersions->eliminar($apkVersion);

        return $this->successResponse(null, 'Versión eliminada');
    }

    /**
     * Descarga autenticada para el panel web (Sistemas revisando cualquier
     * versión del historial).
     */
    public function descargar(ApkVersion $apkVersion): StreamedResponse
    {
        Gate::authorize('viewAny', ApkVersion::class);

        abort_unless(Storage::disk('privado')->exists($apkVersion->file_path), 404);

        return Storage::disk('privado')->download($apkVersion->file_path, $apkVersion->file_name);
    }

    /**
     * Consulta pública antes del login: solo metadata de la versión vigente
     * y una URL firmada temporal. La descarga no requiere Bearer y sigue
     * comprobando firma y publicación; administrar APKs requiere sistemas.
     */
    public function vigente(): JsonResponse
    {
        $apkVersion = $this->apkVersions->vigente();

        if ($apkVersion === null) {
            return $this->successResponse(null)->header('Cache-Control', 'no-store');
        }

        return $this->successResponse([
            'version' => $apkVersion->version,
            'build_number' => $apkVersion->build_number,
            'descripcion' => $apkVersion->descripcion,
            'file_size' => $apkVersion->file_size,
            'sha256' => $apkVersion->sha256,
            'publicada_at' => $apkVersion->publicada_at,
            'descargar_url' => URL::temporarySignedRoute('apk-updates.descargar', now()->addMinutes(30), ['apkVersion' => $apkVersion->id]),
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Sin auth:sanctum — protegida únicamente por la firma de la URL (ver
     * vigente()). Solo sirve la versión que sigue siendo la vigente en el
     * momento de la descarga, para que un enlace reusado durante su ventana
     * de 30 minutos no sirva una versión ya despublicada.
     */
    public function descargarFirmado(ApkVersion $apkVersion): StreamedResponse
    {
        abort_unless($apkVersion->publicada, 404);
        abort_unless(Storage::disk('privado')->exists($apkVersion->file_path), 404);

        return Storage::disk('privado')->download($apkVersion->file_path, $apkVersion->file_name);
    }
}
