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
        Schema::table('creditos_prendarios', function (Blueprint $table): void {
            $table->boolean('interes_solicitud_especial')->default(false)->after('interes');
            $table->string('motivo_interes')->nullable()->after('interes_solicitud_especial');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table): void {
            $table->dropColumn(['interes_solicitud_especial', 'motivo_interes']);
        });
    }
};
