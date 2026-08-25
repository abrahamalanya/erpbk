<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every TIMESTAMP column in the schema (created_at/updated_at everywhere,
     * plus abierta_at, cerrada_at, fecha_resolucion, fecha_aprobacion,
     * generado_at, impreso_at, firmado_at, atendido_at, read_at, failed_at,
     * expires_at, last_used_at, email_verified_at), enumerated from
     * information_schema so nothing gets missed.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS_PER_TABLE = [
        'agencias' => ['created_at', 'updated_at'],
        'bancos' => ['created_at', 'updated_at'],
        'bien_credito_prendario' => ['created_at', 'updated_at'],
        'bien_fotos' => ['created_at', 'updated_at'],
        'bienes' => ['created_at', 'updated_at'],
        'billetajes' => ['created_at', 'updated_at', 'fecha_resolucion'],
        'boveda_ciclos' => ['created_at', 'updated_at', 'abierta_at', 'cerrada_at'],
        'boveda_movimientos' => ['created_at', 'updated_at'],
        'bovedas' => ['created_at', 'updated_at'],
        'caja_ciclos' => ['created_at', 'updated_at', 'abierta_at', 'cerrada_at'],
        'caja_movimientos' => ['created_at', 'updated_at'],
        'cajas' => ['created_at', 'updated_at'],
        'clientes' => ['created_at', 'updated_at'],
        'conceptos' => ['created_at', 'updated_at'],
        'conciliaciones_bancarias' => ['created_at', 'updated_at'],
        'configuraciones_credito_prendario' => ['created_at', 'updated_at'],
        'creditos_prendarios' => ['created_at', 'updated_at', 'fecha_aprobacion'],
        'cuenta_bancaria_movimientos' => ['created_at', 'updated_at'],
        'cuentas_bancarias' => ['created_at', 'updated_at'],
        'cuotas_credito_prendario' => ['created_at', 'updated_at'],
        'documentos_credito_prendario' => ['created_at', 'updated_at', 'generado_at', 'impreso_at', 'firmado_at'],
        'empresas' => ['created_at', 'updated_at'],
        'failed_jobs' => ['failed_at'],
        'intereses_bien' => ['created_at', 'updated_at', 'atendido_at'],
        'movimiento_fotos' => ['created_at', 'updated_at'],
        'notifications' => ['created_at', 'updated_at', 'read_at'],
        'password_reset_tokens' => ['created_at'],
        'permissions' => ['created_at', 'updated_at'],
        'personal_access_tokens' => ['created_at', 'updated_at', 'expires_at', 'last_used_at'],
        'roles' => ['created_at', 'updated_at'],
        'users' => ['created_at', 'updated_at', 'email_verified_at'],
    ];

    /**
     * The MySQL connection's time_zone here has always been 'SYSTEM', which on
     * every server this app has run on so far already resolves to
     * America/Lima (UTC-5) — so every TIMESTAMP column has always been
     * silently round-tripped through Lima's offset on write/read. That
     * self-cancelled and produced the correct instant while
     * config('app.timezone') was 'UTC' (PHP wrote/read UTC-labelled digits;
     * MySQL's Lima-based conversion handed the same digits back). Flipping
     * app.timezone to America/Lima (see config/app.php, changed in the same
     * deploy as this migration) fixes new writes, but re-labels every
     * ALREADY-STORED value as 5 hours later than it really happened. This
     * shifts every existing one back by 5 hours first, so history keeps its
     * true, original moment under the new label.
     *
     * Only meant to run once, exactly when app.timezone changes from UTC to
     * America/Lima — running it again (or without that config change) would
     * introduce the same 5-hour error in the other direction.
     */
    public function up(): void
    {
        $this->shift('SUB');
    }

    /**
     * Reverses the shift — only correct if run back-to-back with reverting
     * app.timezone to UTC.
     */
    public function down(): void
    {
        $this->shift('ADD');
    }

    private function shift(string $direction): void
    {
        // Only meaningful against the real MySQL database, whose TIMESTAMP
        // columns actually went through the SYSTEM/Lima round-trip described
        // above. A fresh test sqlite database has no pre-existing rows to
        // correct (and doesn't support DATE_SUB/DATE_ADD SQL syntax anyway).
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::COLUMNS_PER_TABLE as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("UPDATE `{$table}` SET `{$column}` = DATE_{$direction}(`{$column}`, INTERVAL 5 HOUR) WHERE `{$column}` IS NOT NULL");
            }
        }
    }
};
