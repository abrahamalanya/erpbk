<?php

namespace App\Modules\Sistemas\Services;

use App\Modules\Sistemas\Models\ApkVersion;
use App\Modules\Usuario\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Sube, publica y elimina compilaciones APK (ver ApkInspectorService para la
 * inspección de contenido). El archivo vive en el disco `privado` — igual que
 * el certificado SUNAT — nunca en `public/`.
 */
final class ApkVersionService
{
    public function __construct(private readonly ApkInspectorService $inspector) {}

    /**
     * @param  array{application_id: string, version: string, build_number: int, descripcion: ?string}  $datos
     */
    public function subir(User $actor, array $datos, UploadedFile $archivo): ApkVersion
    {
        $esperado = config('apk_updates.application_id');
        abort_unless($datos['application_id'] === $esperado, 422, "El applicationId no corresponde a esta aplicación (esperado: {$esperado}).");

        $inspeccion = $this->inspector->inspeccionar($archivo->getRealPath());
        abort_unless($inspeccion['es_apk'], 422, 'El archivo no tiene una estructura válida de APK (falta AndroidManifest.xml o classes.dex).');
        abort_unless($inspeccion['firmado'], 422, 'El APK no está firmado.');

        $maximo = ApkVersion::query()->where('application_id', $datos['application_id'])->max('build_number');
        abort_if($maximo !== null && $datos['build_number'] <= $maximo, 422, "El número de compilación debe ser mayor a {$maximo} (la última versión subida para esta aplicación).");

        $hash = hash_file('sha256', $archivo->getRealPath());
        $directorio = "apk/{$datos['application_id']}";
        $nombreArchivo = "{$datos['build_number']}-{$archivo->hashName()}";
        $path = Storage::disk('privado')->putFileAs($directorio, $archivo, $nombreArchivo);
        abort_if($path === false, 500, 'No se pudo guardar el APK.');

        return ApkVersion::query()->create([
            'application_id' => $datos['application_id'],
            'version' => $datos['version'],
            'build_number' => $datos['build_number'],
            'descripcion' => $datos['descripcion'] ?? null,
            'file_path' => $path,
            'file_name' => $archivo->getClientOriginalName(),
            'file_size' => $archivo->getSize(),
            'sha256' => $hash,
            'subido_por' => $actor->id,
        ]);
    }

    public function publicar(ApkVersion $apkVersion): ApkVersion
    {
        return DB::transaction(function () use ($apkVersion): ApkVersion {
            ApkVersion::query()
                ->where('application_id', $apkVersion->application_id)
                ->where('id', '!=', $apkVersion->id)
                ->where('publicada', true)
                ->lockForUpdate()
                ->update(['publicada' => false, 'publicada_at' => null]);

            $actual = ApkVersion::query()->lockForUpdate()->findOrFail($apkVersion->id);
            $actual->update(['publicada' => true, 'publicada_at' => now()]);

            return $actual;
        });
    }

    public function eliminar(ApkVersion $apkVersion): void
    {
        abort_if($apkVersion->publicada, 409, 'No se puede eliminar la versión vigente. Publica otra versión antes de eliminarla.');

        Storage::disk('privado')->delete($apkVersion->file_path);
        $apkVersion->delete();
    }

    public function vigente(): ?ApkVersion
    {
        return ApkVersion::query()
            ->where('application_id', config('apk_updates.application_id'))
            ->where('publicada', true)
            ->first();
    }
}
