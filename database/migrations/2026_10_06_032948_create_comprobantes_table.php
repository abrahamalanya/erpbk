<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cabecera del comprobante de pago electrónico.
     *
     * ## Snapshot fiscal — lo más importante de esta tabla
     *
     * El sistema re-renderiza el PDF al vuelo desde esta fila (mismo patrón
     * que DocumentoCreditoService). Si guardáramos solo la referencia al
     * cliente y a la empresa, corregir el RUC de un cliente mañana haría que
     * el PDF mostrara un dato DISTINTO del que ya se envió firmado a SUNAT.
     * Por eso se copian aquí emisor_*, receptor_* y todos los valores con los
     * que se emitió. Es un snapshot inmutable de la emisión.
     *
     * ## Sobre serie / numero
     *
     * NO confundir con `caja_movimientos.serie` / `.numero`, que son la
     * boleta de un tercero que la empresa RECIBE (gastos). Acá es el
     * comprobante que la empresa EMITE. Para ligar ambos, usar FK, nunca los
     * strings (FACTURACION.md §6).
     *
     * ## Numeración
     *
     * `correlativo` se consume dentro de la transacción que inserta la fila
     * y no se libera jamás. Si el envío a SUNAT falla, el comprobante conserva
     * su número y se reintenta con el mismo.
     */
    public function up(): void
    {
        Schema::create('comprobantes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('empresa_id')->constrained()->restrictOnDelete();
            $table->foreignId('agencia_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cliente_id')->constrained()->restrictOnDelete();

            // Qué originó el comprobante. Un crédito puede tener más de uno
            // (nota de crédito, corrección), por eso es morph y no belongsTo.
            $table->string('origen_tipo')->nullable();
            $table->unsignedBigInteger('origen_id')->nullable();

            $table->foreignId('series_comprobante_id')->constrained('series_comprobantes')->restrictOnDelete();

            // --- Identificación del documento ---
            $table->char('tipo_documento', 2);
            $table->char('serie', 4);
            $table->unsignedBigInteger('correlativo');
            $table->string('numero', 20);

            $table->date('fecha_emision');
            $table->time('hora_emision');
            $table->string('moneda', 3)->default('PEN');

            // --- Importes ---
            $table->decimal('subtotal', 12, 2);
            $table->decimal('descuento_global', 12, 2)->default(0);
            $table->decimal('igv', 12, 2);
            $table->decimal('total', 12, 2);
            $table->string('importe_letras');

            $table->decimal('porcentaje_igv', 5, 2);

            // --- Forma de pago ---
            $table->string('forma_pago')->default('01');
            $table->string('medio_pago')->nullable();
            $table->string('codigo_detraccion')->nullable();

            // --- Estado (enum EstadoComprobante) ---
            $table->string('estado')->default('borrador');

            $table->string('observaciones')->nullable();

            // --- Snapshot del emisor ---
            $table->string('emisor_ruc', 11);
            $table->string('emisor_razon_social');
            $table->string('emisor_nombre_comercial')->nullable();
            $table->string('emisor_domicilio')->nullable();
            $table->char('emisor_ubigeo', 6)->nullable();
            $table->string('emisor_direccion_completa')->nullable();

            // --- Snapshot del receptor ---
            $table->string('receptor_tipo_documento', 20);
            $table->string('receptor_numero_documento', 20);
            $table->string('receptor_nombre');
            $table->string('receptor_domicilio')->nullable();
            $table->char('receptor_ubigeo', 6)->nullable();
            $table->string('receptor_email')->nullable();

            // --- Respuesta de SUNAT (CDR) ---
            $table->string('cdr_codigo', 10)->nullable();
            $table->text('cdr_descripcion')->nullable();
            $table->char('codigo_seguridad', 4)->nullable();
            $table->string('hash_cdr', 64)->nullable();
            $table->json('cdr_digests')->nullable();
            $table->timestamp('enviado_at')->nullable();
            $table->timestamp('aceptado_at')->nullable();

            // --- Anulación ---
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_at')->nullable();
            $table->string('motivo_anulacion')->nullable();
            $table->timestamp('baja_comunicada_at')->nullable();

            // --- Relaciones entre comprobantes ---
            // Documento que este corrige (nota de crédito, o baja).
            $table->foreignId('documento_origen_id')->nullable()->constrained('comprobantes')->nullOnDelete();

            // --- Archivos (disco privado, nunca public/) ---
            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->string('zip_path')->nullable();

            $table->foreignId('generado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['empresa_id', 'serie', 'correlativo'], 'comprobantes_empresa_serie_correlativo_unique');
            $table->index(['empresa_id', 'fecha_emision']);
            $table->index(['empresa_id', 'estado']);
            $table->index(['origen_tipo', 'origen_id']);
            $table->index(['cliente_id', 'fecha_emision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobantes');
    }
};
