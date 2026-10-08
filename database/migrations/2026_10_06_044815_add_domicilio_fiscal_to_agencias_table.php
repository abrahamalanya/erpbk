<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Domicilio fiscal y punto de emisión de cada agencia.
     *
     * En SUNAT cada agencia es un **punto de emisión** distinto, con su propio
     * juego de series. Esta migración deja el domicilio ya descompuesto y el
     * código de establecimiento listo, porque los dos van juntos en el
     * `cac:AccountingSupplierParty` del XML.
     *
     * `ruc` en la agencia es opcional a propósito: la mayoría de sucursales
     * emiten con el RUC de la casa matriz. Solo se llena cuando SUNAT autoriza
     * un RUC propio para ese establecimiento.
     */
    public function up(): void
    {
        Schema::table('agencias', function (Blueprint $table): void {
            $table->string('ruc', 11)->nullable()->after('telefono');
            $table->string('domicilio_calle', 150)->nullable()->after('ruc');
            $table->string('domicilio_numero', 20)->nullable()->after('domicilio_calle');
            $table->string('domicilio_interior', 20)->nullable()->after('domicilio_numero');
            $table->foreignId('ubigeo_distrito_id')->nullable()->after('domicilio_interior')
                ->constrained('ubigeo_distritos')->nullOnDelete();
            $table->string('correo_facturacion', 255)->nullable()->after('ubigeo_distrito_id');

            // Código de establecimiento que asigna SUNAT. La casa matriz suele
            // ser 1. Va en el XML como parte de la identidad del emisor.
            $table->string('codigo_establecimiento', 4)->nullable()->after('correo_facturacion');
        });
    }

    public function down(): void
    {
        Schema::table('agencias', function (Blueprint $table): void {
            $table->dropForeign(['ubigeo_distrito_id']);
            $table->dropColumn([
                'ruc',
                'domicilio_calle',
                'domicilio_numero',
                'domicilio_interior',
                'ubigeo_distrito_id',
                'correo_facturacion',
                'codigo_establecimiento',
            ]);
        });
    }
};
