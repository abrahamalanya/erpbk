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
        Schema::table('cuenta_bancaria_movimientos', function (Blueprint $table) {
            $table->string('origen')->nullable()->after('concepto');
            $table->string('grupo_id')->nullable()->after('origen');

            $table->index('grupo_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cuenta_bancaria_movimientos', function (Blueprint $table) {
            $table->dropIndex(['grupo_id']);
            $table->dropColumn(['origen', 'grupo_id']);
        });
    }
};
