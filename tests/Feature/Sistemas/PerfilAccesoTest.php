<?php

use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\CajaCiclo;
use App\Modules\Caja\Models\CajaMovimiento;
use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cobranza\Models\Cobro;
use App\Modules\Credito\Events\CreditoActualizado;
use App\Modules\Credito\Models\Credito;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Facturacion\Models\Comprobante;
use App\Modules\Sistemas\Models\Modulo;
use App\Modules\Sistemas\Models\PerfilAcceso;
use App\Modules\Sistemas\Services\ModuloService;
use App\Modules\Ubicacion\Events\UbicacionAsesorActualizada;
use App\Modules\Ubicacion\Models\UbicacionAsesor;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Models\IdempotencyKey;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    $this->admin = User::factory()->forAgencia($this->agencia)->create();
    $this->admin->assignRole('administrador_agencia');
    $this->asesor = User::factory()->forAgencia($this->agencia)->create();
    $this->asesor->assignRole('asesor');
    $this->perfil = PerfilAcceso::query()->create(['nombre' => 'Diarios', 'activo' => true]);
    $this->perfil->modulos()->sync(Modulo::whereIn('key', ['diario', 'mi_caja'])->pluck('id'));
});

it('manages profiles only as sistemas without changing existing permissions', function () {
    Sanctum::actingAs($this->admin);
    $this->postJson('/api/perfiles-acceso', ['nombre' => 'Nuevo', 'activo' => true, 'modulos' => ['diario']])->assertForbidden();
    $this->getJson('/api/perfiles-acceso')->assertSuccessful();
    $this->admin->assignRole('sistemas');
    $before = $this->asesor->getAllPermissions()->pluck('name')->all();
    $this->postJson('/api/perfiles-acceso', ['nombre' => 'Nuevo', 'activo' => true, 'modulos' => ['diario']])->assertCreated()->assertJsonPath('data.modulos.0.key', 'diario');
    expect($this->asesor->getAllPermissions()->pluck('name')->all())->toBe($before);
    $this->postJson('/api/perfiles-acceso', ['nombre' => 'Invalido', 'activo' => true, 'modulos' => ['inventado']])->assertUnprocessable();
});

it('assigns profiles and preserves assignment for old clients omitting the field', function () {
    Sanctum::actingAs($this->admin);
    $this->putJson("/api/usuarios/{$this->asesor->id}", ['perfil_acceso_id' => $this->perfil->id])->assertSuccessful();
    $this->putJson("/api/usuarios/{$this->asesor->id}", ['nombre' => 'Actualizado'])->assertSuccessful()->assertJsonPath('data.perfil_acceso_id', $this->perfil->id);
    Sanctum::actingAs($this->asesor->fresh());
    $this->getJson('/api/auth/me')->assertSuccessful()->assertJsonPath('data.perfil_acceso.nombre', 'Diarios');
    $this->getJson('/api/ventas')->assertForbidden();
    $this->postJson('/api/creditos-vehiculares', [])->assertForbidden();
});

it('preserves legacy module inheritance and overrides for users without profiles', function () {
    $service = app(ModuloService::class);
    expect($service->modulosEfectivos($this->asesor))->toEqualCanonicalizing(['diario', 'prendario', 'vehicular', 'hipotecario']);
    $this->asesor->update(['modulos' => []]);
    expect($service->modulosEfectivos($this->asesor))->toBe([]);
    $this->asesor->update(['modulos' => ['prendario']]);
    expect($service->modulosEfectivos($this->asesor))->toBe(['prendario']);
});

it('uses one profile across multiple roles and never grants operation permissions', function () {
    $this->asesor->assignRole('supervisor');
    $this->asesor->update(['perfil_acceso_id' => $this->perfil->id, 'modulos' => ['prendario']]);
    expect(app(ModuloService::class)->modulosEfectivos($this->asesor))->toEqualCanonicalizing(['diario', 'mi_caja']);
    $this->perfil->modulos()->sync(Modulo::where('key', 'empresas')->pluck('id'));
    Sanctum::actingAs($this->asesor);
    $this->postJson('/api/empresas', ['nombre' => 'No autorizada'])->assertForbidden();
});

it('hides excluded credit types from administrative lists and direct operations', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create();
    $credito = Credito::factory()->paraBien($bien)->create();
    $this->admin->update(['perfil_acceso_id' => $this->perfil->id]);
    Sanctum::actingAs($this->admin);
    $this->getJson('/api/creditos-prendarios')->assertSuccessful()->assertJsonCount(0, 'data.data');
    $this->getJson("/api/creditos-prendarios/{$credito->id}")->assertNotFound();
    $this->postJson("/api/creditos-prendarios/{$credito->id}/aprobar", [])->assertNotFound();
});

it('applies profile edits to existing sessions and blocks legacy overrides', function () {
    $this->asesor->update(['perfil_acceso_id' => $this->perfil->id]);
    Sanctum::actingAs($this->asesor);
    $this->getJson('/api/auth/me')->assertJsonFragment(['modulos_efectivos' => ['mi_caja', 'diario']]);
    $this->perfil->modulos()->detach();
    $this->getJson('/api/auth/me')->assertJsonPath('data.modulos_efectivos', []);
    Sanctum::actingAs($this->admin);
    $this->putJson("/api/usuarios/{$this->asesor->id}/modulos", ['modulos' => ['prendario']])->assertStatus(409);
});

it('does not deactivate assigned profiles or assign inactive profiles', function () {
    $this->asesor->update(['perfil_acceso_id' => $this->perfil->id]);
    $this->admin->assignRole('sistemas');
    Sanctum::actingAs($this->admin);
    $this->putJson("/api/perfiles-acceso/{$this->perfil->id}", ['nombre' => 'Diarios', 'activo' => false, 'modulos' => ['diario'], 'updated_at' => $this->perfil->updated_at->toISOString()])->assertStatus(409);
    $this->perfil->update(['activo' => false]);
    expect(app(ModuloService::class)->modulosEfectivos($this->asesor->fresh()))->toBe([]);
    $this->putJson("/api/usuarios/{$this->admin->id}", ['perfil_acceso_id' => $this->perfil->id])->assertUnprocessable();
});

it('retains the explicit sistemas exception', function () {
    $this->admin->assignRole('sistemas');
    $this->admin->update(['perfil_acceso_id' => $this->perfil->id]);
    expect(app(ModuloService::class)->modulosEfectivos($this->admin))->toContain('ventas', 'prendario');
});

it('does not let an administrator remove their own restriction', function () {
    $this->perfil->modulos()->attach(Modulo::where('key', 'usuarios')->firstOrFail());
    $this->admin->update(['perfil_acceso_id' => $this->perfil->id]);
    Sanctum::actingAs($this->admin);
    $this->putJson("/api/usuarios/{$this->admin->id}", ['perfil_acceso_id' => null])->assertForbidden();
});

it('rejects stale profile edits without overwriting a newer selection', function () {
    $this->admin->assignRole('sistemas');
    Sanctum::actingAs($this->admin);
    $payload = ['nombre' => 'Diarios', 'activo' => true, 'modulos' => ['diario'], 'updated_at' => $this->perfil->updated_at->toISOString()];
    $this->travel(1)->seconds();
    $this->putJson("/api/perfiles-acceso/{$this->perfil->id}", $payload)->assertSuccessful();
    $payload['modulos'] = ['prendario'];
    $this->putJson("/api/perfiles-acceso/{$this->perfil->id}", $payload)->assertStatus(409);
    expect($this->perfil->modulos()->pluck('key')->all())->toBe(['diario']);
});

it('checks current access before replaying an idempotent response', function () {
    $this->asesor->update(['perfil_acceso_id' => $this->perfil->id]);
    IdempotencyKey::query()->create([
        'key_hash' => hash('sha256', $this->asesor->id.'|venta-anterior'),
        'user_id' => $this->asesor->id, 'method' => 'POST', 'path' => '/api/ventas',
        'response_status' => 201, 'response_body' => '{"datos":"venta reservada"}',
    ]);
    Sanctum::actingAs($this->asesor);
    $this->postJson('/api/ventas', [], ['Idempotency-Key' => 'venta-anterior'])->assertForbidden();
});

it('filters related financial records and notifications by the allowed credit type', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create();
    $prendario = Credito::factory()->paraBien($bien)->create();
    $diario = Credito::factory()->paraBien($bien)->create(['tipo_credito' => 'diario']);
    foreach ([$prendario, $diario] as $credito) {
        Cobro::factory()->create(['empresa_id' => $this->empresa->id, 'credito_id' => $credito->id]);
        $this->admin->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'credito', 'data' => ['credito_id' => $credito->id, 'mensaje' => 'Reservado']]);
    }
    $this->admin->update(['perfil_acceso_id' => $this->perfil->id]);
    Sanctum::actingAs($this->admin);
    $this->perfil->modulos()->attach(Modulo::where('key', 'cobranzas')->firstOrFail());
    $this->getJson('/api/cobros')->assertSuccessful()->assertJsonCount(1, 'data.data');
    $this->getJson('/api/notificaciones')->assertSuccessful()->assertJsonCount(1, 'data.notificaciones.data');
});

it('filters invoices and credit notes by their originating credit', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create();
    $prendario = Credito::factory()->paraBien($bien)->create();
    $diario = Credito::factory()->paraBien($bien)->create(['tipo_credito' => 'diario']);
    foreach ([$prendario, $diario] as $credito) {
        $original = Comprobante::factory()->create(['empresa_id' => $this->empresa->id, 'origen_tipo' => 'credito', 'origen_id' => $credito->id, 'correlativo' => $credito->id * 2]);
        Comprobante::factory()->create(['empresa_id' => $this->empresa->id, 'origen_tipo' => 'nota_credito', 'origen_id' => $original->id, 'correlativo' => $credito->id * 2 + 1]);
    }
    $this->admin->update(['perfil_acceso_id' => $this->perfil->id]);
    Sanctum::actingAs($this->admin);
    expect(Comprobante::query()->count())->toBe(2);
});

it('does not broadcast excluded credit information to restricted recipients', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create();
    $credito = Credito::factory()->paraBien($bien)->create();
    $this->asesor->update(['perfil_acceso_id' => $this->perfil->id]);
    $event = new CreditoActualizado($credito, collect([$this->asesor, $this->admin]));
    expect($event->broadcastOn())->toHaveCount(1);
});

it('does not send company location events to a previously subscribed restricted user', function () {
    $this->admin->update(['perfil_acceso_id' => $this->perfil->id]);
    $ubicacion = new UbicacionAsesor(['empresa_id' => $this->empresa->id, 'user_id' => $this->asesor->id]);
    $event = new UbicacionAsesorActualizada($ubicacion, $this->asesor);
    $channels = collect($event->broadcastOn())->pluck('name')->all();
    expect($channels)->toBe(['private-sistemas.ubicaciones-asesores']);
});

it('provides scoped client references for credit forms without opening client administration', function () {
    $cliente = Cliente::factory()->forAgencia($this->agencia)->create(['asesor_id' => $this->asesor->id]);
    $this->asesor->update(['perfil_acceso_id' => $this->perfil->id]);
    Sanctum::actingAs($this->asesor);
    $this->getJson('/api/clientes')->assertForbidden();
    $this->getJson('/api/opciones/clientes')->assertSuccessful()->assertJsonPath('data.data.0.id', $cliente->id)->assertJsonMissingPath('data.data.0.direccion');
    $this->getJson("/api/opciones/clientes/{$cliente->id}")->assertSuccessful()->assertJsonPath('data.id', $cliente->id)->assertJsonMissingPath('data.foto_dni_url');
});

it('preserves the real cash balance after assigning a profile that excludes historical credit types', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create();
    $prendario = Credito::factory()->paraBien($bien)->create();
    $diario = Credito::factory()->paraBien($bien)->create(['tipo_credito' => 'diario']);
    $caja = Caja::factory()->create(['user_id' => $this->asesor->id, 'empresa_id' => $this->empresa->id, 'agencia_id' => $this->agencia->id]);
    $ciclo = CajaCiclo::query()->create(['caja_id' => $caja->id, 'empresa_id' => $this->empresa->id, 'fecha' => now()->toDateString(), 'estado' => 'abierta', 'abierta_at' => now(), 'saldo_apertura' => 1000]);
    foreach ([[$prendario->id, 'egreso', 200], [$diario->id, 'ingreso', 50]] as [$creditoId, $tipo, $monto]) {
        CajaMovimiento::query()->create(['caja_ciclo_id' => $ciclo->id, 'empresa_id' => $this->empresa->id, 'tipo' => $tipo, 'monto' => $monto, 'credito_id' => $creditoId, 'registrado_por' => $this->asesor->id, 'fecha_caja' => now()->toDateString(), 'medio' => 'efectivo']);
    }
    expect($ciclo->saldoActual())->toBe('850.00');
    $this->asesor->update(['perfil_acceso_id' => $this->perfil->id]);
    Sanctum::actingAs($this->asesor);
    expect($ciclo->saldoActual())->toBe('850.00');
});

it('does not make occupied collateral available when its credit is hidden by a profile', function () {
    $bien = Bien::factory()->forAgencia($this->agencia)->create();
    Credito::factory()->paraBien($bien)->create(['estado' => 'activo']);
    $this->admin->update(['perfil_acceso_id' => $this->perfil->id]);
    Sanctum::actingAs($this->admin);
    expect(Credito::query()->count())->toBe(0);
    expect(Bien::query()->disponibles()->whereKey($bien->id)->exists())->toBeFalse();
});
