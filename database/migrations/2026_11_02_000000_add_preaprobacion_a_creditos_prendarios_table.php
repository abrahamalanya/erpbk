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
        Schema::table('creditos_prendarios', function (Blueprint $table) {
            $table->foreignId('preaprobado_por')->nullable()->after('registrado_por')->constrained('users')->nullOnDelete();
            $table->timestamp('fecha_preaprobacion')->nullable()->after('fecha_aprobacion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preaprobado_por');
            $table->dropColumn('fecha_preaprobacion');
        });
    }
};
