<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parámetros de facturación por empresa (no por agencia: el IGV, la moneda
     * y el modo de entorno son de la empresa, no del establecimiento). A
     * diferencia de configuraciones_venta NO tiene eje agencia_id.
     *
     * `interes_incluye_igv` es el interruptor más importante de esta tabla:
     * en un crédito prendario el interés que paga el cliente normalmente ya
     * trae el IGV adentro (100 de interés = 84.75 de base + 15.25 de IGV), y
     * hay que "sacarlo" para poder desglosar el comprobante. Si una empresa
     * cobra el interés sin IGV, se pone en false y el IGV se suma encima.
     */
    public function up(): void
    {
        Schema::create('configuraciones_facturacion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->unique()->constrained()->restrictOnDelete();

            // beta = homologación en e-beta.sunat.gob.pe; produccion = real.
            $table->string('modo')->default('beta');

            $table->decimal('igv_porcentaje', 5, 2)->default(18.00);
            $table->string('moneda', 3)->default('PEN');
            $table->boolean('interes_incluye_igv')->default(true);

            // Series por defecto: cuál se usa cuando el cliente tiene RUC
            // (factura) y cuál cuando es persona natural (boleta). La serie
            // concreta se resuelve en SerieService según la agencia.
            $table->string('serie_factura_defecto', 4)->nullable();
            $table->string('serie_boleta_defecto', 4)->nullable();

            // Si es false, se avisa pero se deja emitir; en true, se bloquea.
            // El umbral legal está en FACTURACION.md §12 (verificar vigencia).
            $table->boolean('bloquear_si_supera_umbral')->default(false);

            $table->boolean('activo')->default(false);
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones_facturacion');
    }
};
