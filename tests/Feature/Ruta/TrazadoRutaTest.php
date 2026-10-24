<?php

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Credito\Models\ConfiguracionCredito;
use App\Modules\Credito\Models\Credito;
use App\Modules\Credito\Models\CuotaCredito;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Ruta\Services\TrazadoRutaService;
use App\Modules\Ubicacion\Models\UbicacionAsesor;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    ConfiguracionCredito::factory()->deEmpresa($this->empresa)->create([
        'tipo_credito' => 'diario',
        'interes_default' => 15, 'plazo_dias' => 30, 'dias_espera_mora' => 15,
        'dias_minimo_interes' => 15, 'tasa_mora_diaria' => 0.05, 'max_cuotas' => 45,
    ]);

    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');

    config(['services.openrouteservice.key' => 'clave-de-prueba']);
    Cache::flush();
});

/**
 * Cliente en mora con una cuota vencida hace $dias días: entra en la ruta, y
 * los más atrasados van primero. Prefijada con tr_ porque RutaCobranzaTest ya
 * declara globalmente su propio helper de mora.
 */
function tr_clienteEnMora(User $asesor, Agencia $agencia, ?float $latitud, ?float $longitud, int $dias): Cliente
{
    $cliente = Cliente::factory()->asignadoA($asesor)->create(['latitud' => $latitud, 'longitud' => $longitud]);
    $credito = Credito::factory()->diario()->create([
        'registrado_por' => $asesor->id, 'empresa_id' => $agencia->empresa_id, 'agencia_id' => $agencia->id,
        'cliente_id' => $cliente->id, 'estado' => 'activo', 'plazo_dias' => 30,
        'fecha_desembolso' => now()->subDays($dias + 1)->toDateString(),
        'fecha_vencimiento' => now()->addDays(29 - $dias)->toDateString(),
    ]);
    CuotaCredito::factory()->paraCredito($credito)->create([
        'numero_cuota' => 1, 'fecha_vencimiento' => now()->subDays($dias)->toDateString(),
        'monto_capital' => 10, 'monto_interes' => 1, 'monto_total' => 11,
    ]);

    return $cliente;
}

/**
 * Respuesta GeoJSON de OpenRouteService: coordenadas en [longitud, latitud].
 *
 * @param  list<array{0: float, 1: float}>  $coordenadasLngLat
 */
function respuestaOrs(array $coordenadasLngLat, float $distancia = 1234.5, float $duracion = 300.4): array
{
    return ['features' => [[
        'geometry' => ['coordinates' => $coordenadasLngLat],
        'properties' => ['summary' => ['distance' => $distancia, 'duration' => $duracion]],
    ]]];
}

it('returns the street route between the paradas of the ruta, in visit order', function () {
    tr_clienteEnMora($this->asesor, $this->agencia, -8.3791, -74.5539, 10);
    tr_clienteEnMora($this->asesor, $this->agencia, -8.3850, -74.5500, 5);
    tr_clienteEnMora($this->asesor, $this->agencia, null, null, 1);

    Http::fake(['api.heigit.org/*' => Http::response(respuestaOrs([
        [-74.5539, -8.3791], [-74.5520, -8.3800], [-74.5500, -8.3850],
    ]))]);

    Sanctum::actingAs($this->asesor, ['*']);
    $data = $this->getJson('/api/rutas-cobranza/trazado')->assertSuccessful()->json('data');

    expect($data['trazado'])->toBe([[-8.3791, -74.5539], [-8.38, -74.552], [-8.385, -74.55]])
        ->and($data['distancia_m'])->toBe(1235)
        ->and($data['duracion_s'])->toBe(300)
        ->and($data['aproximado'])->toBeFalse();

    // Se manda la clave y las paradas con coordenadas en [lng, lat], en orden
    // de visita (la de 10 días de atraso primero).
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'clave-de-prueba')
        && $request['coordinates'] === [[-74.5539, -8.3791], [-74.55, -8.385]]);
});

it('starts the trazado at the asesor current GPS location when desde_ubicacion_actual is sent', function () {
    tr_clienteEnMora($this->asesor, $this->agencia, -8.3850, -74.5500, 5);
    tr_clienteEnMora($this->asesor, $this->agencia, -8.3900, -74.5450, 1);

    UbicacionAsesor::query()->create([
        'user_id' => $this->asesor->id, 'latitud' => -8.3791, 'longitud' => -74.5539,
        'capturado_en' => now(), 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id,
    ]);

    Http::fake(['api.heigit.org/*' => Http::response(respuestaOrs([
        [-74.5539, -8.3791], [-74.5500, -8.3850], [-74.5450, -8.3900],
    ]))]);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->getJson('/api/rutas-cobranza/trazado?desde_ubicacion_actual=1')->assertSuccessful();

    // La ubicación en vivo del asesor va primero, antes que la parada más atrasada.
    Http::assertSent(fn (Request $request): bool => $request['coordinates'][0] === [-74.5539, -8.3791]
        && count($request['coordinates']) === 3);
});

it('ignores desde_ubicacion_actual when the asesor never sent a GPS ping', function () {
    tr_clienteEnMora($this->asesor, $this->agencia, -8.3850, -74.5500, 5);
    tr_clienteEnMora($this->asesor, $this->agencia, -8.3900, -74.5450, 1);

    Http::fake(['api.heigit.org/*' => Http::response(respuestaOrs([
        [-74.5500, -8.3850], [-74.5450, -8.3900],
    ]))]);

    Sanctum::actingAs($this->asesor, ['*']);
    $this->getJson('/api/rutas-cobranza/trazado?desde_ubicacion_actual=1')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => count($request['coordinates']) === 2);
});

it('caches the route so reopening the map does not call the service again', function () {
    Http::fake(['api.heigit.org/*' => Http::response(respuestaOrs([[-74.55, -8.38], [-74.56, -8.39]]))]);
    $servicio = app(TrazadoRutaService::class);

    $servicio->trazar([[-8.38, -74.55], [-8.39, -74.56]]);
    $servicio->trazar([[-8.38, -74.55], [-8.39, -74.56]]);

    Http::assertSentCount(1);
});

it('falls back to straight lines, marked aproximado, when the service fails or has no key', function () {
    Http::fake(['api.heigit.org/*' => Http::response(['error' => ['code' => 2010, 'message' => 'Could not find routable point']], 404)]);
    $paradas = [[-8.38, -74.55], [-8.39, -74.56]];

    $fallo = app(TrazadoRutaService::class)->trazar($paradas);

    config(['services.openrouteservice.key' => null]);
    $sinClave = app(TrazadoRutaService::class)->trazar($paradas);

    expect($fallo)->toBe(['trazado' => $paradas, 'distancia_m' => 0, 'duracion_s' => 0, 'aproximado' => true])
        ->and($sinClave['aproximado'])->toBeTrue()
        ->and($sinClave['trazado'])->toBe($paradas);
    Http::assertSentCount(1);
});

it('splits routes longer than 50 paradas into continuous tramos', function () {
    Http::fake(fn (Request $request) => Http::response(respuestaOrs($request['coordinates'], 100, 10)));
    $paradas = array_map(fn (int $i): array => [-8.0 - $i / 1000, -74.0 - $i / 1000], range(0, 59));

    $resultado = app(TrazadoRutaService::class)->trazar($paradas);

    // Tramos 0-49 y 49-59: la parada 49 se comparte y no se repite.
    Http::assertSentCount(2);
    expect($resultado['trazado'])->toHaveCount(60)
        ->and($resultado['trazado'])->toEqual($paradas)
        ->and($resultado['distancia_m'])->toBe(200);
});

it('does not call the service for a ruta with fewer than 2 paradas', function () {
    Http::fake();

    expect(app(TrazadoRutaService::class)->trazar([[-8.38, -74.55]])['trazado'])->toBe([[-8.38, -74.55]]);
    Http::assertNothingSent();
});

it('denies the trazado of another asesor ruta to an asesor', function () {
    $otro = User::factory()->forAgencia($this->agencia)->create();
    $otro->assignRole('asesor');

    Sanctum::actingAs($this->asesor, ['*']);
    $this->getJson("/api/rutas-cobranza/trazado?asesor_id={$otro->id}")->assertForbidden();
});
