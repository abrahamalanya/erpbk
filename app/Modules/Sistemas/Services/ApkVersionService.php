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
     * Sube una compilación nueva. applicationId/version/build_number NUNCA se
     * toman de lo que el admin escriba en el formulario — se leen del
     * AndroidManifest.xml real dentro del APK. Esa era exactamente la causa
     * del ciclo de "hay una actualización" que nunca se resolvía: un build
     * local podía generar un APK con un versionCode viejo (si no se
     * regeneró el proyecto nativo tras cambiar app.config.ts) mientras el
     * admin tipeaba a mano el número que creía correcto; el backend
     * publicaba esa metadata falsa y ningún dispositivo podía alcanzarla
     * nunca instalando el archivo real.
     */
    public function subir(User $actor, ?string $descripcion, UploadedFile $archivo): ApkVersion
    {
        $inspeccion = $this->inspector->inspeccionar($archivo->getRealPath());
        abort_unless($inspeccion['es_apk'], 422, 'El archivo no tiene una estructura válida de APK (falta AndroidManifest.xml o classes.dex).');
        abort_unless($inspeccion['firmado'], 422, 'El APK no está firmado.');

        $applicationId = $inspeccion['package'];
        $buildNumber = $inspeccion['version_code'];
        $version = $inspeccion['version_name'];
        abort_if($applicationId === null || $buildNumber === null || $version === null, 422,
            'No se pudieron leer package/versionCode/versionName reales del AndroidManifest.xml. Verifica que sea un APK release válido generado por Gradle/EAS.');

        $esperado = config('apk_updates.application_id');
        abort_unless($applicationId === $esperado, 422, "El applicationId real del APK ({$applicationId}) no corresponde a esta aplicación (esperado: {$esperado}).");

        $maximo = ApkVersion::query()->where('application_id', $applicationId)->max('build_number');
        abort_if($maximo !== null && $buildNumber <= $maximo, 422,
            "El versionCode real de este APK es {$buildNumber}, que no es mayor a {$maximo} (la última compilación subida). ".
            'Si acabas de cambiar la versión en app.config.ts, regenera el proyecto nativo (`npx expo prebuild --platform android --clean`) antes de compilar: de lo contrario Gradle sigue usando el versionCode anterior.');

        $vigente = ApkVersion::query()->where('application_id', $applicationId)->where('publicada', true)->first();
        if ($vigente !== null && $vigente->certificado_sha256 !== null && $inspeccion['certificado_sha256'] !== null
            && $vigente->certificado_sha256 !== $inspeccion['certificado_sha256']) {
            abort(422, 'La firma de este APK no coincide con la de la versión vigente. Publicarlo así impedirá actualizar sobre dispositivos que ya tengan la versión anterior instalada (Android exige desinstalar antes). Verifica que el build use las mismas credenciales de firma (credentials.json) de siempre.');
        }

        $hash = hash_file('sha256', $archivo->getRealPath());
        $directorio = "apk/{$applicationId}";
        $nombreArchivo = "{$buildNumber}-{$archivo->hashName()}";
        $path = Storage::disk('privado')->putFileAs($directorio, $archivo, $nombreArchivo);
        abort_if($path === false, 500, 'No se pudo guardar el APK.');

        return ApkVersion::query()->create([
            'application_id' => $applicationId,
            'version' => $version,
            'build_number' => $buildNumber,
            'descripcion' => $descripcion,
            'file_path' => $path,
            'file_name' => $archivo->getClientOriginalName(),
            'file_size' => $archivo->getSize(),
            'sha256' => $hash,
            'certificado_sha256' => $inspeccion['certificado_sha256'],
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
