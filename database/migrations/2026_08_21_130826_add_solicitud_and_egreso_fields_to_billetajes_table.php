<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('billetajes', function (Blueprint $table) {
            $table->string('motivo')->nullable()->after('monto');
            $table->string('medio_recepcion')->nullable()->after('motivo');
            $table->string('datos_recepcion')->nullable()->after('medio_recepcion');

            $table->string('medio_egreso')->nullable()->after('motivo_rechazo');
            $table->string('canal_egreso')->nullable()->after('medio_egreso');
            $table->foreignId('cuenta_bancaria_id')->nullable()->after('canal_egreso')->constrained('cuentas_bancarias')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billetajes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cuenta_bancaria_id');
            $table->dropColumn(['motivo', 'medio_recepcion', 'datos_recepcion', 'medio_egreso', 'canal_egreso']);
        });
    }
};
