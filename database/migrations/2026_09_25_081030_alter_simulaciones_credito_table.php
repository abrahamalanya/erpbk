<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simulaciones_credito', function (Blueprint $table) {
            // 'simple' (default, todos los tipos) | 'compuesto' — mismo criterio que creditos_prendarios.tipo_interes.
            $table->string('tipo_interes')->default('simple')->after('interes');
        });
    }

    public function down(): void
    {
        Schema::table('simulaciones_credito', function (Blueprint $table) {
            $table->dropColumn('tipo_interes');
        });
    }
};
