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
        Schema::table('cobros', function (Blueprint $table) {
            $table->decimal('descuento', 12, 2)->nullable()->after('mora');
            $table->string('motivo_descuento')->nullable()->after('descuento');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cobros', function (Blueprint $table) {
            $table->dropColumn(['descuento', 'motivo_descuento']);
        });
    }
};
