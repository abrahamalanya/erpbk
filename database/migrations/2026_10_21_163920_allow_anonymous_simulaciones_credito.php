<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('simulaciones_credito', function (Blueprint $table) {
            $table->foreignId('agencia_id')->nullable()->change();
            $table->foreignId('cliente_id')->nullable()->change();
            $table->string('modo_calculo')->default('numero_cuotas');
            $table->decimal('importe_cuota', 12, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('simulaciones_credito')->whereNull('agencia_id')->orWhereNull('cliente_id')->exists()) {
            throw new RuntimeException('Existen simulaciones sin cliente o agencia. Asigna su contexto antes de revertir esta migración.');
        }

        Schema::table('simulaciones_credito', function (Blueprint $table) {
            $table->foreignId('agencia_id')->nullable(false)->change();
            $table->foreignId('cliente_id')->nullable(false)->change();
            $table->dropColumn(['modo_calculo', 'importe_cuota']);
        });
    }
};
