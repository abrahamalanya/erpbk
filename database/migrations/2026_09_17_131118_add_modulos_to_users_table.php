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
        Schema::table('users', function (Blueprint $table) {
            // Null = sin override (hereda los módulos por defecto de su(s)
            // rol(es)). Un array explícito (incluso vacío) reemplaza ese
            // default para este usuario puntual — ver ModuloService.
            $table->json('modulos')->nullable()->after('supervisor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('modulos');
        });
    }
};
