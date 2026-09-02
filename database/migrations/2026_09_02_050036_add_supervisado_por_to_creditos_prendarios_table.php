<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Supervisado por" — an admin de agencia or supervisor picked per
     * crédito. Informational only (it does not widen visibility). Required
     * for vehicular / hipotecario, unused by prendario.
     */
    public function up(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table) {
            $table->foreignId('supervisado_por')->nullable()->after('registrado_por')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supervisado_por');
        });
    }
};
