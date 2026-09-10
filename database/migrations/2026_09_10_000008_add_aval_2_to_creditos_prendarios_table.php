<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Segundo aval (garante) del crédito hipotecario — otra persona
     * registrada como cliente. Opcional; el expediente arma una sección por
     * cada garante presente.
     */
    public function up(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table): void {
            $table->foreignId('aval_2_id')->nullable()->after('aval_id')->constrained('clientes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('aval_2_id');
        });
    }
};
