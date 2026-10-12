<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El digest que SUNAT realmente devuelve (y que CdrParser extrae) es un
     * SHA-512 en base64 — 88 caracteres, no el SHA-256 en hex (64) para el
     * que se dimensionó la columna. Verificado con una boleta real aceptada
     * en beta: truncaba con "Data too long for column 'hash_cdr'".
     *
     * Con SQL directo (no Schema::table()->change()) porque el proyecto no
     * tiene doctrine/dbal instalado. SQLite (los tests) no impone el límite
     * de longitud de un VARCHAR y no entiende `MODIFY`, así que ahí no hace
     * falta tocar nada.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE comprobantes MODIFY hash_cdr VARCHAR(128) NULL');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE comprobantes MODIFY hash_cdr VARCHAR(64) NULL');
        }
    }
};
