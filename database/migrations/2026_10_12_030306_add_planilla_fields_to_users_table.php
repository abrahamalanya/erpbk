<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Si el usuario va en la planilla y con qué régimen: sistema de pensión
     * (AFP u ONP, descuento al trabajador) y seguro de salud (EsSalud o SIS,
     * aporte del empleador). La planilla los toma de acá en cada línea.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('en_planilla')->default(false)->after('estado');
            $table->string('sistema_pension', 10)->nullable()->after('en_planilla');
            $table->string('seguro_salud', 10)->nullable()->after('sistema_pension');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['en_planilla', 'sistema_pension', 'seguro_salud']);
        });
    }
};
