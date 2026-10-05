<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Teléfono de la agencia. La fotocheck lo imprime en el aviso de
     * extravío ("comunicarse al TELÉFONO: ..."), así que sin esto no había de
     * dónde sacar el número. Antes solo existía empresas.celular_cobranzas,
     * que es el celular de cobranzas de la empresa, no el de la agencia.
     */
    public function up(): void
    {
        Schema::table('agencias', function (Blueprint $table): void {
            $table->string('telefono', 30)->nullable()->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::table('agencias', function (Blueprint $table): void {
            $table->dropColumn('telefono');
        });
    }
};
