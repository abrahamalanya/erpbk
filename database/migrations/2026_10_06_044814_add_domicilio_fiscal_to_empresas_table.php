<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Domicilio fiscal estructurado del emisor.
     *
     * `empresas.domicilio_legal` es un texto libre de 255 caracteres que
     * alimenta los contratos en PDF, pero el XML de SUNAT exige la dirección
     * descompuesta: calle, número, interior y el **ubigeo de 6 dígitos**. Sin
     * esto el comprobante no se puede emitir (FACTURACION.md §2.2).
     *
     * `ruc` pasa a ser único y no puede quedar vacío: una empresa sin RUC no
     * puede emitir comprobantes, así que el estado se documenta en vez de
     * dejarlo implícito con un nullable. Se rellenan los existentes con un RUC
     * de pruebas solo en entorno local.
     */
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table): void {
            $table->string('domicilio_calle', 150)->nullable()->after('domicilio_legal');
            $table->string('domicilio_numero', 20)->nullable()->after('domicilio_calle');
            $table->string('domicilio_interior', 20)->nullable()->after('domicilio_numero');
            $table->foreignId('ubigeo_distrito_id')->nullable()->after('domicilio_interior')
                ->constrained('ubigeo_distritos')->nullOnDelete();

            // Correo donde SUNAT y el cliente reciben el comprobante.
            $table->string('correo_facturacion', 255)->nullable()->after('ubigeo_distrito_id');

            // Teléfono que debe figurar en el comprobante.
            $table->string('telefono_facturacion', 30)->nullable()->after('correo_facturacion');
        });

        // El RUC identifica al emisor ante SUNAT: dos empresas con el mismo RUC
        // permitirían emitir comprobantes cruzados.
        Schema::table('empresas', function (Blueprint $table): void {
            $table->unique('ruc', 'empresas_ruc_unique');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table): void {
            $table->dropUnique('empresas_ruc_unique');
            $table->dropForeign(['ubigeo_distrito_id']);
            $table->dropColumn([
                'domicilio_calle',
                'domicilio_numero',
                'domicilio_interior',
                'ubigeo_distrito_id',
                'correo_facturacion',
                'telefono_facturacion',
            ]);
        });
    }
};
