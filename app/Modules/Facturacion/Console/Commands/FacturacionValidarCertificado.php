<?php

namespace App\Modules\Facturacion\Console\Commands;

use App\Modules\Facturacion\Models\CredencialSunat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Valida que el certificado digital de una empresa puede firmar correctamente.
 *
 * Se usa en:
 * - POST /api/facturacion/credenciales/validar (botón "Validar" en UI)
 * - Comando semanal programado para alerta proactiva
 */
class FacturacionValidarCertificado extends Command
{
    protected $signature = 'facturacion:validar-certificado {empresa_id? : ID de la empresa. Si no se pasa, valida todas.}';

    protected $description = 'Valida el certificado digital (.pfx) firmando un XML de prueba';

    public function handle(): int
    {
        $empresaId = $this->argument('empresa_id');

        $query = CredencialSunat::query()
            ->whereNotNull('certificado_path')
            ->with('empresa');

        if ($empresaId !== null) {
            $query->where('empresa_id', $empresaId);
        }

        $credenciales = $query->get();

        if ($credenciales->isEmpty()) {
            $this->warn('No hay credenciales con certificado para validar.');

            return Command::SUCCESS;
        }

        $this->info("Validando {$credenciales->count()} certificado(s)...");

        $validos = 0;
        $invalidos = 0;
        $porVencer = 0;

        foreach ($credenciales as $credencial) {
            $empresa = $credencial->empresa;
            $this->line("\n📋 {$empresa->nombre} (RUC: {$empresa->ruc})");

            try {
                // 1. Verificar que el archivo existe
                if (! Storage::disk('privado')->exists($credencial->certificado_path)) {
                    throw new \RuntimeException("Archivo .pfx no encontrado: {$credencial->certificado_path}");
                }

                // 2. Leer y parsear el PKCS#12
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

                // 3. Verificar fechas de vigencia
                $inicio = openssl_x509_parse($cert)['validFrom_time_t'] ?? 0;
                $fin = openssl_x509_parse($cert)['validTo_time_t'] ?? 0;
                $ahora = time();

                $venceEn = $fin - $ahora;
                $diasRestantes = (int) ($venceEn / 86400);

                $this->line('   Válido desde: '.date('d/m/Y', $inicio));
                $this->line('   Vence el: '.date('d/m/Y', $fin));
                $this->line("   Días restantes: {$diasRestantes}");

                if ($diasRestantes <= 0) {
                    $this->error('   ❌ CERTIFICADO VENCIDO');
                    $invalidos++;

                    continue;
                }

                if ($diasRestantes <= 60) {
                    $this->warn("   ⚠️ Por vencer en {$diasRestantes} días");
                    $porVencer++;
                }

                // 4. Verificar que la clave privada coincide. openssl_pkcs12_read()
                // devuelve la llave como string PEM; openssl_pkey_get_details()
                // necesita el objeto, no el string — hay que resolverlo primero.
                $llave = openssl_pkey_get_private($pkey);
                $keyDetails = $llave ? openssl_pkey_get_details($llave) : false;
                if (! $keyDetails) {
                    throw new \RuntimeException('No se pudo obtener detalles de la clave privada');
                }

                // 5. Probar firma de un XML de prueba (SHA-1 como exige SUNAT)
                $xmlPrueba = '<test>validacion</test>';
                $firma = '';
                $okFirma = openssl_sign($xmlPrueba, $firma, $pkey, OPENSSL_ALGO_SHA1);

                if (! $okFirma) {
                    throw new \RuntimeException('Fallo al firmar XML de prueba (SHA-1)');
                }

                // 6. Verificar la firma
                $okVerificacion = openssl_verify($xmlPrueba, $firma, $cert, OPENSSL_ALGO_SHA1);
                if ($okVerificacion !== 1) {
                    throw new \RuntimeException('La firma generada no se puede verificar');
                }

                $this->line('   ✅ Certificado válido y firmando correctamente (SHA-1)');

                // Actualizar timestamp de validación
                $credencial->update([
                    'ultima_validacion_at' => now(),
                    'ultima_validacion_ok' => true,
                    'ultima_validacion_error' => null,
                    'certificado_vence_en' => date('Y-m-d', $fin),
                ]);

                $validos++;

            } catch (\Throwable $e) {
                $this->error("   ❌ Error: {$e->getMessage()}");

                $credencial->update([
                    'ultima_validacion_at' => now(),
                    'ultima_validacion_ok' => false,
                    'ultima_validacion_error' => $e->getMessage(),
                ]);

                $invalidos++;
            }
        }

        $this->newLine();
        $this->info("Resultado: {$validos} válidos | {$invalidos} inválidos | {$porVencer} por vencer (≤60 días)");

        return $invalidos > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
