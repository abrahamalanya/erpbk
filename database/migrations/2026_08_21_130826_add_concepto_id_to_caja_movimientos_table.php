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
        Schema::table('caja_movimientos', function (Blueprint $table) {
            $table->foreignId('concepto_id')->nullable()->after('concepto')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('caja_movimientos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('concepto_id');
        });
    }
};
