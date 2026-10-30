<?php

namespace App\Modules\Sistemas\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreApkVersionRequest extends FormRequest
{
    /**
     * Autorización real vía Gate en el controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // No se usa `mimes:apk`: el MIME real detectado en el servidor
            // puede salir `application/zip` (un APK es un ZIP) y dar un falso
            // negativo. `extensions:apk` es un chequeo barato de nombre; la
            // validación de contenido real la hace ApkInspectorService.
            //
            // version/build_number/application_id NO se piden: se leen del
            // AndroidManifest.xml real dentro del archivo (ApkVersionService),
            // nunca de lo que el admin escriba a mano.
            'apk' => ['required', 'file', 'extensions:apk', 'max:'.(config('apk_updates.max_size_mb') * 1024)],
            'descripcion' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'apk.extensions' => 'El archivo debe ser un .apk',
            'apk.max' => 'El APK no puede superar el tamaño máximo permitido.',
        ];
    }
}
