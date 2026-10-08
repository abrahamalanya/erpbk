<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credenciales de SUNAT por empresa. Nunca van en config/ ni en .env porque
     * la instancia es multi-empresa: el certificado de una financiera no puede
     * ser el mismo que el de otra (FACTURACION.md §5.11).
     *
     * `usuario_sol` y `clave_sol` usan el cast `encrypted` del modelo, igual
     * que la contraseña del .pfx — en la base se guardan cifrados, no en
     * texto plano.
     *
     * El .pfx vive en disco PRIVADO (nunca en `public/`); aquí solo la ruta.
     * El archivo no es versionable ni debe poder descargarse desde el navegador.
     */
    public function up(): void
    {
        Schema::create('credenciales_sunat', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->unique()->constrained()->restrictOnDelete();

            $table->string('usuario_sol')->nullable();
            $table->text('clave_sol')->nullable();

            $table->string('certificado_path')->nullable();
            $table->text('certificado_password')->nullable();

            // El RUC emisor vive en `empresas.ruc`; se copia aquí para poder
            // validar que el certificado pertenezca a la misma razón social
            // antes de intentar firmar nada.
            $table->string('razon_social_certificado')->nullable();
            $table->date('certificado_vence_en')->nullable();
            $table->timestamp('certificado_cargado_at')->nullable();

            // Resultado del último `facturacion:validar-certificado`.
            $table->timestamp('ultima_validacion_at')->nullable();
            $table->boolean('ultima_validacion_ok')->nullable();
            $table->text('ultima_validacion_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credenciales_sunat');
    }
};
