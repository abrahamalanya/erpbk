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
        Schema::create('qr_generados', function (Blueprint $table) {
            $table->id();
            $table->text('url');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('generado_por');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qr_generados');
    }
};
