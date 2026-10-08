<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitácora de envíos a SUNAT: una fila por intento.
     *
     * Sirve para tres cosas:
     *  - depurar rechazos (el CDR guarda el XML exacto que devolvió SUNAT);
     *  - probar el reintento sin tocar el comprobante;
     *  - responderle al cliente con evidencia cuando hay discrepancias entre lo
     *    que el sistema dice y lo que SUNAT tiene registrado.
     *
     * Solo se escribe desde el job de envío; nunca desde un request de usuario.
     */
    public function up(): void
    {
        Schema::create('comprobante_envios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comprobante_id')->constrained('comprobantes')->cascadeOnDelete();

            $table->unsignedSmallInteger('intento')->default(1);

            // pendiente | enviado | aceptado | error
            $table->string('estado')->default('pendiente');

            // Nombre del archivo que exige SUNAT:
            // {RUC}-{TipoDoc}-{Serie}-{Correlativo}.xml
            $table->string('nombre_archivo', 60)->nullable();
            $table->text('request_xml')->nullable();

            // Respuesta
            $table->string('cdr_codigo', 10)->nullable();
            $table->text('cdr_descripcion')->nullable();
            $table->text('cdr_xml')->nullable();

            $table->text('excepcion')->nullable();
            $table->unsignedInteger('duracion_ms')->nullable();

            $table->timestamps();

            $table->index(['comprobante_id', 'intento']);
            $table->index(['estado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobante_envios');
    }
};
