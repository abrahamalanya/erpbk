<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Ruta\Models\VisitaCliente;
use App\Modules\Ruta\Services\VisitaClienteService;
use App\Modules\Ubicacion\Models\DispositivoGps;
use App\Modules\Ubicacion\Models\UbicacionAsesor;
use App\Modules\Usuario\Models\User;
use Carbon\CarbonInterface;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    Sanctum::actingAs($this->asesor, ['*']);
});

/** Crédito diario vencido hace 2 días (en mora, entra a la ruta de hoy) para $cliente. */
function creditoEnMoraParaVisita(Empresa $empresa, Agencia $agencia, Cliente $cliente, User $asesor): Credito
{
    $credito = Credito::factory()->diario()->create([
        'registrado_por' => $asesor->id,
        'empresa_id' => $empresa->id,
        'agencia_id' => $agencia->id,
        'cliente_id' => $cliente->id,
        'estado' => 'activo',
        'fecha_desembolso' => now()->subDays(2)->toDateString(),
        'fecha_vencimiento' => now()->addDays(28)->toDateString(),
        'plazo_dias' => 30,
    ]);

    CuotaCredito::factory()->paraCredito($credito)->create([
        'numero_cuota' => 1,
        'fecha_vencimiento' => now()->subDays(1)->toDateString(),
        'monto_capital' => 10, 'monto_interes' => 1, 'monto_total' => 11,
    ]);

    return $credito;
}

function enviarPing(float $lat, float $lon, CarbonInterface $capturadoEn, float $precision = 10): void
{
    if ($capturadoEn->isFuture()) {
        test()->travelTo($capturadoEn);
    }
    test()->postJson('/api/ubicaciones-asesores', [
        'latitud' => $lat,
        'longitud' => $lon,
        'precision_metros' => $precision,
        'capturado_en' => $capturadoEn->toISOString(),
    ])->assertSuccessful();
}

it('keeps device permanence independent when the same advisor uses two phones', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)->create(['latitud' => -8.0, 'longitud' => -74.0]);
    creditoEnMoraParaVisita($this->empresa, $this->agencia, $cliente, $this->asesor);
    $devices = collect(['phone-a', 'phone-b'])->map(fn ($key) => DispositivoGps::create([
        'identificador' => hash('sha256', $key), 'user_id' => $this->asesor->id, 'vinculado_en' => now(),
    ]));
    $t0 = now()->startOfSecond();
    $ping = function ($device, $minute, $lat = -8.0) use ($t0) {
        app(VisitaClienteService::class)->procesarPing($this->asesor, new UbicacionAsesor([
            'dispositivo_id' => $device->id, 'latitud' => $lat, 'longitud' => -74.0, 'precision_metros' => 10,
            'capturado_en' => $t0->copy()->addMinutes($minute),
        ]));
    };
    $ping($devices[0], 0);
    $ping($devices[1], 1);
    expect(VisitaCliente::count())->toBe(2);
    $ping($devices[1], 2, -9.0);
    expect(VisitaCliente::count())->toBe(1);
    $ping($devices[0], 2);
    $ping($devices[0], 3);
    expect(VisitaCliente::where('dispositivo_id', $devices[0]->id)->first()->confirmada_en)->not->toBeNull();
});

it('confirms the visit after 3 continuous minutes with good precision inside the radius', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)
        ->create(['latitud' => -8.0, 'longitud' => -74.0]);
    creditoEnMoraParaVisita($this->empresa, $this->agencia, $cliente, $this->asesor);

    $t0 = now();
    enviarPing(-8.0, -74.0, $t0);
    enviarPing(-8.0, -74.0, $t0->copy()->addMinute());
    enviarPing(-8.0, -74.0, $t0->copy()->addMinutes(2));
    enviarPing(-8.0, -74.0, $t0->copy()->addMinutes(3));

    $visita = VisitaCliente::query()->where('cliente_id', $cliente->id)->where('asesor_id', $this->asesor->id)->first();

    expect($visita)->not->toBeNull()
        ->and($visita->confirmada_en)->not->toBeNull();
});

it('does not confirm when GPS precision is insufficient', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)
        ->create(['latitud' => -8.0, 'longitud' => -74.0]);
    creditoEnMoraParaVisita($this->empresa, $this->agencia, $cliente, $this->asesor);

    enviarPing(-8.0, -74.0, now(), precision: 1000);

    expect(VisitaCliente::query()->where('cliente_id', $cliente->id)->exists())->toBeFalse();
});

it('does not confirm before 3 minutes elapse', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)
        ->create(['latitud' => -8.0, 'longitud' => -74.0]);
    creditoEnMoraParaVisita($this->empresa, $this->agencia, $cliente, $this->asesor);

    $t0 = now();
    enviarPing(-8.0, -74.0, $t0);
    enviarPing(-8.0, -74.0, $t0->copy()->addMinute());

    $visita = VisitaCliente::query()->where('cliente_id', $cliente->id)->first();

    expect($visita)->not->toBeNull()
        ->and($visita->confirmada_en)->toBeNull();
});

it('resets the streak when the asesor leaves the radius before confirming', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)
        ->create(['latitud' => -8.0, 'longitud' => -74.0]);
    creditoEnMoraParaVisita($this->empresa, $this->agencia, $cliente, $this->asesor);

    $t0 = now();
    enviarPing(-8.0, -74.0, $t0);
    // Lejos del radio (varios km) a los 60s: la racha se reinicia (fila borrada).
    enviarPing(-9.0, -75.0, $t0->copy()->addSeconds(60));

    expect(VisitaCliente::query()->where('cliente_id', $cliente->id)->exists())->toBeFalse();

    // Vuelve al radio 10s después: nueva racha, inicio_en es este ping, no el primero.
    $vuelta = $t0->copy()->addSeconds(70);
    enviarPing(-8.0, -74.0, $vuelta);

    $visita = VisitaCliente::query()->where('cliente_id', $cliente->id)->first();
    // Comparar contra la propia ubicación guardada (mismo pipeline de
    // timezone/serialización) en vez del Carbon local: evita falsos
    // negativos por la conversión de zona horaria al persistir/leer.
    $ubicacionGuardada = UbicacionAsesor::query()->where('user_id', $this->asesor->id)->first();

    expect($visita)->not->toBeNull()
        ->and($visita->confirmada_en)->toBeNull()
        ->and($visita->inicio_en->timestamp)->toBe($ubicacionGuardada->capturado_en->timestamp);
});

it('does not accumulate time across two separate visits under the minimum', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)
        ->create(['latitud' => -8.0, 'longitud' => -74.0]);
    creditoEnMoraParaVisita($this->empresa, $this->agencia, $cliente, $this->asesor);

    $t0 = now();
    // Primera racha: 100s dentro del radio (no alcanza los 180s = 3 min).
    enviarPing(-8.0, -74.0, $t0);
    enviarPing(-8.0, -74.0, $t0->copy()->addSeconds(100));
    // Sale del radio: la racha se borra.
    enviarPing(-9.0, -75.0, $t0->copy()->addSeconds(110));
    // Segunda racha: otros 100s dentro del radio — si se acumulara con la
    // primera (200s) confirmaría; como no debe acumularse, sigue sin confirmar.
    $inicioSegunda = $t0->copy()->addSeconds(120);
    enviarPing(-8.0, -74.0, $inicioSegunda);
    enviarPing(-8.0, -74.0, $inicioSegunda->copy()->addSeconds(100));

    $visita = VisitaCliente::query()->where('cliente_id', $cliente->id)->first();

    expect($visita->confirmada_en)->toBeNull();
});

it('keeps a confirmed visit even after the asesor leaves the radius afterward', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)
        ->create(['latitud' => -8.0, 'longitud' => -74.0]);
    creditoEnMoraParaVisita($this->empresa, $this->agencia, $cliente, $this->asesor);

    $t0 = now();
    enviarPing(-8.0, -74.0, $t0);
    enviarPing(-8.0, -74.0, $t0->copy()->addMinute());
    enviarPing(-8.0, -74.0, $t0->copy()->addMinutes(2));
    enviarPing(-8.0, -74.0, $t0->copy()->addMinutes(3));

    $confirmadaEn = VisitaCliente::query()->where('cliente_id', $cliente->id)->first()->confirmada_en;
    expect($confirmadaEn)->not->toBeNull();

    // Se aleja del radio después de confirmar: no se borra ni se cambia.
    enviarPing(-9.0, -75.0, $t0->copy()->addMinutes(4));

    $visita = VisitaCliente::query()->where('cliente_id', $cliente->id)->first();

    expect($visita)->not->toBeNull()
        ->and($visita->confirmada_en->toDateTimeString())->toBe($confirmadaEn->toDateTimeString());
});

it('uses the negocio coordinates when lugar_cobranza is negocio, never falling back to casa', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)->create([
        'lugar_cobranza' => 'negocio',
        'latitud' => -8.0, 'longitud' => -74.0,
        'latitud_negocio' => -8.5, 'longitud_negocio' => -74.5,
    ]);
    creditoEnMoraParaVisita($this->empresa, $this->agencia, $cliente, $this->asesor);

    // Está exactamente en la CASA, no en el negocio: no debe confirmar ni
    // siquiera iniciar la racha, porque el lugar elegido es el negocio.
    enviarPing(-8.0, -74.0, now());
    expect(VisitaCliente::query()->where('cliente_id', $cliente->id)->exists())->toBeFalse();

    // Está en el NEGOCIO: ahora sí evalúa proximidad.
    $t0 = now()->addMinute();
    enviarPing(-8.5, -74.5, $t0);
    enviarPing(-8.5, -74.5, $t0->copy()->addMinute());
    enviarPing(-8.5, -74.5, $t0->copy()->addMinutes(2));
    enviarPing(-8.5, -74.5, $t0->copy()->addMinutes(3));

    $visita = VisitaCliente::query()->where('cliente_id', $cliente->id)->first();
    expect($visita)->not->toBeNull()
        ->and($visita->confirmada_en)->not->toBeNull();
});

it('does not evaluate a cliente whose chosen lugar_cobranza has no coordinates, without falling back', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->asignadoA($this->asesor)->create([
        'lugar_cobranza' => 'negocio',
        'latitud' => -8.0, 'longitud' => -74.0,
        'latitud_negocio' => null, 'longitud_negocio' => null,
    ]);
    creditoEnMoraParaVisita($this->empresa, $this->agencia, $cliente, $this->asesor);

    $t0 = now();
    enviarPing(-8.0, -74.0, $t0);
    enviarPing(-8.0, -74.0, $t0->copy()->addMinutes(3));

    expect(VisitaCliente::query()->where('cliente_id', $cliente->id)->exists())->toBeFalse();
});
