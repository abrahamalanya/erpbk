<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Programar tareas de Facturación
Schedule::command('comprobantes:reintentar-envio')->everyFifteenMinutes();
Schedule::command('comprobantes:enviar-resumen-diario')->dailyAt('23:00');
Schedule::command('comprobantes:consultar-cdr-resumenes')->hourly(); // Procesa tickets de resumen/baja pendientes
Schedule::command('facturacion:validar-credenciales')->weeklyOn(1, '06:00');
Schedule::command('facturacion:alerta-certificado')->dailyAt('07:00');

// --- Créditos ---
Schedule::command('creditos-prendarios:actualizar-estados')->daily();
Schedule::command('cajas:cerrar-automatico')->daily();
Schedule::command('ventas:cancelar-apartados-vencidos')->daily();