<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Líneas del detalle del comprobante. Cada línea lleva su propio importe de
     * IGV porque el XML lo exige en `cac:InvoiceLine/cac:TaxTotal` además del
     * total del documento — omitirlo es una de las razones más comunes de
     * rechazo.
     *
     * Para el caso de crédito el detalle típico es una línea por concepto
     * cobrado: interés, mora, comisiones. Ver BaseImponibleService.
     */
    public function up(): void
    {
        Schema::create('comprobante_detalles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('comprobante_id')->constrained('comprobantes')->cascadeOnDelete();

            $table->unsignedSmallInteger('orden')->default(1);

            // Catálogo 01 de SUNAT (productos y servicios).
            $table->string('codigo_producto', 20)->nullable();
            $table->string('descripcion');

            // Catálogo 03/UNEI.
            $table->char('unidad_medida', 5)->default('ZZ');

            $table->decimal('cantidad', 14, 4)->default(1);
            $table->decimal('valor_unitario', 12, 4)->default(0);
            $table->decimal('precio_unitario', 12, 4)->default(0);

            $table->decimal('descuento', 12, 2)->default(0);
            $table->decimal('valor_venta', 12, 2);
            $table->decimal('igv', 12, 2);
            $table->decimal('total', 12, 2);

            // Categoría de tributo (catálogo 08): S gravado, G exonerado, etc.
            $table->char('categoria_tributo', 1)->default('S');
            $table->decimal('porcentaje_igv', 5, 2);

            // Vincula la línea con el cobro que la originó, para poder auditar
            // de dónde salió cada concepto facturado.
            $table->foreignId('cobro_id')->nullable()->constrained('cobros')->nullOnDelete();

            $table->timestamps();

            $table->index(['comprobante_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobante_detalles');
    }
};
