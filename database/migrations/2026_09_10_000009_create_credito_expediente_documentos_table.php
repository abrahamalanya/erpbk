<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fotos / capturas que arman el documento "Expediente" de un crédito
     * hipotecario: por rol (deudor / aval1 / aval2 / inmueble) y sección
     * (dni, casa, ubicación Maps, croquis, terreno, trabajo, negocio,
     * suministro, recibo de servicio, central de riesgo, copia/certificado
     * literal). Varias imágenes por rol+sección.
     */
    public function up(): void
    {
        Schema::create('credito_expediente_documentos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credito_id')->constrained('creditos_prendarios')->cascadeOnDelete();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();

            $table->string('rol');      // deudor | aval1 | aval2 | inmueble
            $table->string('seccion');  // dni | casa | negocio | ubicacion_maps | croquis | terreno | trabajo | suministro | recibo_servicio | central_riesgo | copia_literal | certificado_literal
            $table->string('path');
            $table->unsignedInteger('orden')->default(0);

            $table->timestamps();

            $table->index(['credito_id', 'rol', 'seccion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_expediente_documentos');
    }
};
