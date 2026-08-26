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
            $table->foreignId('adenda_de_credito_id')->nullable()->after('refrendo_de_credito_id')
                ->constrained('creditos_prendarios')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('creditos_prendarios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('adenda_de_credito_id');
        });
    }
};
