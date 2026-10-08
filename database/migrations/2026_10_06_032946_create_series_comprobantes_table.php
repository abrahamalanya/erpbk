<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Series autorizadas por SUNAT, por empresa y punto de emisión (agencia).
     *
     * `ultimo_correlativo` es el contador que da la numeración sin huecos: se
     * incrementa con lockForUpdate dentro de la misma transacción que inserta
     * el comprobante, y NUNCA se libera (FACTURACION.md §5.4).
     *
     * ## Por qué la serie es única por EMPRESA y no por (empresa, agencia)
     *
     * Lo intuitivo sería único (empresa_id, agencia_id, serie) para que cada
     * agencia tenga su juego de series. El problema es que `agencia_id` es
     * nullable (casa matriz) y en MySQL y SQLite los NULL NO colisionan en un
     * índice único: se podrían crear dos filas con la misma serie y agencia
     * nula, y ambos emitirían el correlativo 1. Con dos cajas emitiendo el
     * mismo número, SUNAT rechaza y la numeración queda rota.
     *
     * Se usa entonces unique (empresa_id, serie) y `agencia_id` queda como
     * etiqueta del punto de emisión que la usa. Es la restricción MÁS
     * ESTRICTA a propósito: cada agencia pide su propio juego de series a
     * SUNAT (F001, F002... por establecimiento), que además es lo habitual.
     *
     * Relajar esta restricción —si SUNAT exigiera series compartidas entre
     * establecimientos— es un cambio de una línea. Arreglar una colisión de
     * numeración ya ocurrida en producción, no.
     *
     * OJO: esta tabla NO tiene nada que ver con `caja_movimientos.serie` /
     * `.numero`, que son la boleta de un TERCERO que la empresa recibe.
     * Acá es la serie que nosotros emitimos.
     */
    public function up(): void
    {
        Schema::create('series_comprobantes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();

            // Punto de emisión. Nulo = casa matriz (sucursal 1 de SUNAT).
            $table->foreignId('agencia_id')->nullable()->constrained()->restrictOnDelete();

            $table->char('tipo_documento', 2);
            $table->char('serie', 4);
            $table->string('descripcion')->nullable();

            $table->unsignedBigInteger('ultimo_correlativo')->default(0);

            // Rango que autorizó SUNAT: hasta dónde puede llegar la serie.
            $table->unsignedBigInteger('limite_numeracion')->nullable();

            $table->boolean('estado')->default(true);

            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('creado_at')->nullable();

            $table->timestamps();

            $table->unique(['empresa_id', 'serie'], 'series_comprobantes_empresa_serie_unique');
            $table->index(['empresa_id', 'tipo_documento', 'estado']);
            $table->index(['agencia_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series_comprobantes');
    }
};
