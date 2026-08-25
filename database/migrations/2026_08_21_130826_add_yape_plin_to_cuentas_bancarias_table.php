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
        Schema::table('cuentas_bancarias', function (Blueprint $table) {
            $table->boolean('acepta_yape')->default(false)->after('activa');
            $table->string('numero_yape')->nullable()->after('acepta_yape');
            $table->boolean('acepta_plin')->default(false)->after('numero_yape');
            $table->string('numero_plin')->nullable()->after('acepta_plin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cuentas_bancarias', function (Blueprint $table) {
            $table->dropColumn(['acepta_yape', 'numero_yape', 'acepta_plin', 'numero_plin']);
        });
    }
};
