<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discriminator for the shared crédito engine. Existing rows are all
     * prendario; vehicular / hipotecario are added later as their own tipos.
     */
    public function up(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table) {
            $table->string('tipo_credito')->default('prendario')->after('agencia_id');
            $table->index(['empresa_id', 'tipo_credito']);
        });
    }

    public function down(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table) {
            $table->dropIndex(['empresa_id', 'tipo_credito']);
            $table->dropColumn('tipo_credito');
        });
    }
};
