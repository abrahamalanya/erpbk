<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cadena del crédito hipotecario del que este nació por refinanciamiento
     * — igual patrón que refrendo_de_credito_id / adenda_de_credito_id.
     */
    public function up(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table): void {
            $table->foreignId('refinanciamiento_de_credito_id')->nullable()->after('adenda_de_credito_id')
                ->constrained('creditos_prendarios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('refinanciamiento_de_credito_id');
        });
    }
};
