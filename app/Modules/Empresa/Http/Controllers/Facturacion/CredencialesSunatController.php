<?php

namespace App\Modules\Empresa\Http\Controllers\Facturacion;

use App\Modules\Empresa\Http\Requests\Facturacion\StoreCredencialSunatRequest;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Facturacion\Models\CredencialSunat;
use App\Modules\Facturacion\Services\Sunat\FirmanteDigital;
use App\Nucleo\Http\Controllers\Controller;
use App\Nucleo\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controlador para gestionar las credenciales SUNAT de una empresa.
 *
 * Permite: ver (enmascarado), crear/actualizar (subir .pfx), validar, eliminar.
 * Las claves sensibles (clave_sol, certificado_password) se guardan cifradas
 * y nunca se devuelven en la API.
 */
class CredencialesSunatController extends Controller
{
    use ApiResponse;

    /**
     * Muestra las credenciales SUNAT de la empresa (con claves enmascaradas).
     */
    public function show(Empresa $empresa): JsonResponse
    {
        Gate::authorize('view', $empresa);

        $credencial = $empresa->credencialSunat;

        if (! $credencial) {
            return $this->successResponse([
                'existe' => false,
                'usuario_sol' => null,
                'certificado_path' => null,
                'certificado_vence_en' => null,
                'ultima_validacion_at' => null,
                'ultima_validacion_ok' => null,
                'ultima_validacion_error' => null,
                'esta_completa' => false,
                'esta_vencida' => false,
                'dias_para_vencer' => null,
            ]);
        }

        return $this->successResponse([
            'existe' => true,
            'usuario_sol' => $this->enmascarar($credencial->usuario_sol),
            'tiene_clave_sol' => filled($credencial->clave_sol),
            'certificado_path' => $credencial->certificado_path,
            'certificado_vence_en' => $credencial->certificado_vence_en?->toDateString(),
            'certificado_cargado_at' => $credencial->certificado_cargado_at?->toDateTimeString(),
            'ultima_validacion_at' => $credencial->ultima_validacion_at?->toDateTimeString(),
            'ultima_validacion_ok' => $credencial->ultima_validacion_ok,
            'ultima_validacion_error' => $credencial->ultima_validacion_error,
            'esta_completa' => $credencial->estaCompleta(),
            'esta_vencida' => $credencial->estaVencido(),
            'dias_para_vencer' => $credencial->diasParaVencer(),
        ]);
    }

    /**
     * Crea o actualiza las credenciales SUNAT (incluye subida de .pfx).
     */
    public function store(StoreCredencialSunatRequest $request, Empresa $empresa, FirmanteDigital $firmante): JsonResponse
    {
        Gate::authorize('update', $empresa);

        $data = $request->validated();

        // Subir certificado .pfx a disco privado. Los .pfx que emite
        // RENIEC/ECEP suelen cifrar el certificado con un algoritmo que
        // OpenSSL 3 ya no soporta por defecto (ver FirmanteDigital) — se
        // normaliza acá, una sola vez, para que firmar y validar después no
        // vuelvan a toparse con eso.
        $certificadoPath = null;
        if ($request->hasFile('certificado')) {
            $contenido = $firmante->normalizarPfx(
                file_get_contents($request->file('certificado')->getRealPath()),
                $data['certificado_password'] ?? '',
            );

            $certificadoPath = "credenciales_sunat/{$empresa->id}/".$request->file('certificado')->hashName();
            Storage::disk('privado')->put($certificadoPath, $contenido);
        }

        $credencial = CredencialSunat::updateOrCreate(
            ['empresa_id' => $empresa->id],
            [
                'usuario_sol' => $data['usuario_sol'] ?? null,
                'clave_sol' => $data['clave_sol'] ?? null,
                'certificado_path' => $certificadoPath ?? $data['certificado_path'] ?? null,
                'certificado_password' => $data['certificado_password'] ?? null,
                'razon_social_certificado' => $data['razon_social_certificado'] ?? null,
                'certificado_vence_en' => $data['certificado_vence_en'] ?? null,
                'certificado_cargado_at' => $certificadoPath ? now() : null,
                'ultima_validacion_at' => null,
                'ultima_validacion_ok' => null,
                'ultima_validacion_error' => null,
            ]
        );

        // Sincronizar modo_facturacion en empresa para visualización rápida
        if (isset($data['modo_facturacion'])) {
            $empresa->update(['modo_facturacion' => $data['modo_facturacion']]);
        }

        return $this->successResponse($this->formatearCredencial($credencial), 'Credenciales SUNAT guardadas correctamente');
    }

    /**
     * Valida el certificado digital firmando un XML de prueba.
     */
    public function validar(Empresa $empresa): JsonResponse
    {
        Gate::authorize('update', $empresa);

        $credencial = $empresa->credencialSunat;

        if (! $credencial || ! $credencial->estaCompleta()) {
            return $this->errorResponse('Credenciales incompletas: falta usuario SOL, clave SOL o certificado .pfx', 422);
        }

        try {
            // Verificar archivo
            if (! Storage::disk('privado')->exists($credencial->certificado_path)) {
                throw new \RuntimeException('Archivo .pfx no encontrado en disco');
            }

            // Leer y parsear PKCS#12
            $contenido = Storage::disk('privado')->get($credencial->certificado_path);
            $certs = [];
            $ok = openssl_pkcs12_read($contenido, $certs, $credencial->certificado_password);

            if (! $ok) {
                throw new \RuntimeException('No se pudo leer el .pfx: contraseña incorrecta o archivo corrupto');
            }

            $cert = $certs['cert'] ?? null;
            $pkey = $certs['pkey'] ?? null;

            if (! $cert || ! $pkey) {
                throw new \RuntimeException('El .pfx no contiene certificado y/o clave privada');
            }

            // Verificar vigencia
            $fin = openssl_x509_parse($cert)['validTo_time_t'] ?? 0;
            $diasRestantes = (int) (($fin - time()) / 86400);

            if ($diasRestantes <= 0) {
                throw new \RuntimeException('Certificado VENCIDO hace '.abs($diasRestantes).' días');
            }

            // Probar firma SHA-1
            $xmlPrueba = '<test>validacion</test>';
            $firma = '';
            $okFirma = openssl_sign($xmlPrueba, $firma, $pkey, OPENSSL_ALGO_SHA1);

            if (! $okFirma) {
                throw new \RuntimeException('Fallo al firmar XML de prueba (SHA-1)');
            }

            $okVerificacion = openssl_verify($xmlPrueba, $firma, $cert, OPENSSL_ALGO_SHA1);
            if ($okVerificacion !== 1) {
                throw new \RuntimeException('La firma generada no se puede verificar');
            }

            // Actualizar timestamp
            $credencial->update([
                'ultima_validacion_at' => now(),
                'ultima_validacion_ok' => true,
                'ultima_validacion_error' => null,
                'certificado_vence_en' => date('Y-m-d', $fin),
            ]);

            return $this->successResponse([
                'valido' => true,
                'mensaje' => 'Certificado válido y firmando correctamente (SHA-1)',
                'vence_en' => date('Y-m-d', $fin),
                'dias_restantes' => $diasRestantes,
                'ultima_validacion_at' => now()->toDateTimeString(),
            ]);

        } catch (\Throwable $e) {
            $credencial->update([
                'ultima_validacion_at' => now(),
                'ultima_validacion_ok' => false,
                'ultima_validacion_error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Validación fallida: '.$e->getMessage(), 422);
        }
    }

    /**
     * Elimina las credenciales SUNAT de la empresa.
     */
    public function destroy(Empresa $empresa): JsonResponse
    {
        Gate::authorize('update', $empresa);

        $credencial = $empresa->credencialSunat;

        if ($credencial) {
            // Eliminar archivo .pfx del disco
            if ($credencial->certificado_path && Storage::disk('privado')->exists($credencial->certificado_path)) {
                Storage::disk('privado')->delete($credencial->certificado_path);
            }

            $credencial->delete();
        }

        return $this->successResponse(null, 'Credenciales SUNAT eliminadas');
    }

    /**
     * Descarga el certificado .pfx (solo para administradores/sistemas).
     */
    public function descargarCertificado(Empresa $empresa): StreamedResponse
    {
        Gate::authorize('update', $empresa);

        $credencial = $empresa->credencialSunat;

        if (! $credencial || ! $credencial->certificado_path) {
            abort(404, 'No hay certificado subido');
        }

        if (! Storage::disk('privado')->exists($credencial->certificado_path)) {
            abort(404, 'Archivo de certificado no encontrado en disco');
        }

        return Storage::disk('privado')->download(
            $credencial->certificado_path,
            "certificado-{$empresa->ruc}.pfx"
        );
    }

    /**
     * Formatea la credencial para respuesta API (claves enmascaradas).
     */
    private function formatearCredencial(CredencialSunat $credencial): array
    {
        return [
            'existe' => true,
            'usuario_sol' => $this->enmascarar($credencial->usuario_sol),
            'tiene_clave_sol' => filled($credencial->clave_sol),
            'certificado_path' => $credencial->certificado_path,
            'certificado_vence_en' => $credencial->certificado_vence_en?->toDateString(),
            'certificado_cargado_at' => $credencial->certificado_cargado_at?->toDateTimeString(),
            'ultima_validacion_at' => $credencial->ultima_validacion_at?->toDateTimeString(),
            'ultima_validacion_ok' => $credencial->ultima_validacion_ok,
            'ultima_validacion_error' => $credencial->ultima_validacion_error,
            'esta_completa' => $credencial->estaCompleta(),
            'esta_vencida' => $credencial->estaVencido(),
            'dias_para_vencer' => $credencial->diasParaVencer(),
        ];
    }

    private function enmascarar(?string $valor): ?string
    {
        if (! $valor) {
            return null;
        }
        $longitud = strlen($valor);
        if ($longitud <= 4) {
            return str_repeat('*', $longitud);
        }

        return substr($valor, 0, 2).str_repeat('*', $longitud - 4).substr($valor, -2);
    }
}
