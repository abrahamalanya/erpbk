<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Facturacion\Models\ConfiguracionFacturacion;
use App\Modules\Facturacion\Models\CredencialSunat;
use App\Modules\Facturacion\Models\SerieComprobante;
use App\Modules\Facturacion\Services\ConfiguracionFacturacionService;
use App\Modules\Facturacion\Services\SerieService;
use App\Modules\Facturacion\Services\Sunat\CatalogoSunat;
use App\Modules\Facturacion\Tipos\EstadoComprobante;
use App\Modules\Facturacion\Tipos\TipoComprobante;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);

    $this->servicio = new ConfiguracionFacturacionService;
    $this->empresaA = Empresa::factory()->create();
    $this->empresaB = Empresa::factory()->create();
    $this->admin = User::factory()->forEmpresa($this->empresaA)->create();
    $this->admin->assignRole('administrador_general');
});

it('solo deja ver la configuración de facturación de la propia empresa', function () {
    ConfiguracionFacturacion::factory()->deEmpresa($this->empresaA)->create();
    ConfiguracionFacturacion::factory()->deEmpresa($this->empresaB)->create();

    $this->actingAs($this->admin);

    expect(ConfiguracionFacturacion::query()->count())->toBe(1)
        ->and(ConfiguracionFacturacion::query()->first()->empresa_id)->toBe($this->empresaA->id);
});

it('el rol sistemas ve las configuraciones de todas las empresas', function () {
    ConfiguracionFacturacion::factory()->deEmpresa($this->empresaA)->create();
    ConfiguracionFacturacion::factory()->deEmpresa($this->empresaB)->create();

    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    $this->actingAs($sistemas);

    expect(ConfiguracionFacturacion::query()->count())->toBe(2);
});

it('no deja ver las series de otra empresa', function () {
    SerieComprobante::factory()->deEmpresa($this->empresaA)->boleta('B001')->create();
    SerieComprobante::factory()->deEmpresa($this->empresaB)->boleta('B001')->create();

    $this->actingAs($this->admin);

    expect(SerieComprobante::query()->count())->toBe(1);
});

it('no deja resolver la serie de emisión de otra empresa', function () {
    SerieComprobante::factory()->deEmpresa($this->empresaB)->boleta('B001')->create();

    $this->actingAs($this->admin);

    expect(fn () => (new SerieService)
        ->serieParaEmitir($this->empresaB->id, null, TipoComprobante::Boleta))
        ->toThrow(DomainException::class);
});

it('no deja ver las credenciales SUNAT de otra empresa', function () {
    CredencialSunat::factory()->create(['empresa_id' => $this->empresaA, 'usuario_sol' => 'userA']);
    CredencialSunat::factory()->create(['empresa_id' => $this->empresaB, 'usuario_sol' => 'userB']);

    $this->actingAs($this->admin);

    expect(CredencialSunat::query()->count())->toBe(1)
        ->and(CredencialSunat::query()->first()->usuario_sol)->toBe('userA');
});

it('guarda la clave SOL cifrada en la base y no en texto plano', function () {
    $credencial = CredencialSunat::factory()->create([
        'empresa_id' => $this->empresaA,
        'clave_sol' => 'secreto123',
        'certificado_password' => 'clave-pfx',
    ]);

    // Lo que sale del modelo ya viene descifrado...
    expect($credencial->fresh()->clave_sol)->toBe('secreto123')
        ->and($credencial->fresh()->certificado_password)->toBe('clave-pfx');

    // ...pero en la base está cifrado.
    $enBruto = DB::table('credenciales_sunat')
        ->where('id', $credencial->id)
        ->first();

    expect($enBruto->clave_sol)->not->toBe('secreto123')
        ->and($enBruto->clave_sol)->toBeString();
});

it('avisa con un mensaje claro cuando la empresa no tiene configuración', function () {
    expect(fn () => $this->servicio->resolverPara($this->empresaA))
        ->toThrow(DomainException::class, 'Esta empresa no tiene configuración de facturación. Debe configurarse antes de emitir comprobantes.');
});

it('crea la configuración si la empresa aún no tiene ninguna', function () {
    $configuracion = $this->servicio->actualizar($this->empresaA, ['igv_porcentaje' => '18.00']);

    expect($configuracion->exists)->toBeTrue()
        ->and($this->servicio->resolverPara($this->empresaA)->id)->toBe($configuracion->id);
});

it('devuelve 404 —no 403— al pedir una serie de otra empresa', function () {
    // El global scope filtra la fila, así que route-model-binding y
    // findOrFail() fallan como si no existiera (mismo comportamiento que el
    // resto del sistema, ver tests/Feature/Tenancy/TenantIsolationTest.php).
    $serieAjena = SerieComprobante::factory()->deAgencia(
        Agencia::factory()->for($this->empresaB)->create()
    )->boleta()->create();

    $this->actingAs($this->admin);

    expect(fn () => SerieComprobante::query()->findOrFail($serieAjena->id))
        ->toThrow(ModelNotFoundException::class);

    // Y la serie propia sí se encuentra.
    $seriePropia = SerieComprobante::factory()->deAgencia(
        Agencia::factory()->for($this->empresaA)->create()
    )->boleta('B002')->create();

    expect(SerieComprobante::query()->findOrFail($seriePropia->id)->id)->toBe($seriePropia->id);
});

it('sabe si un comprobante ya consumió su número ante SUNAT', function () {
    expect(EstadoComprobante::Borrador->numeroConsumido())->toBeFalse()
        ->and(EstadoComprobante::Generado->numeroConsumido())->toBeTrue()
        ->and(EstadoComprobante::Enviado->numeroConsumido())->toBeTrue()
        ->and(EstadoComprobante::Aceptado->numeroConsumido())->toBeTrue()
        ->and(EstadoComprobante::Anulado->numeroConsumido())->toBeTrue();
});

it('solo considera válido ante SUNAT lo aceptado, lo observado y lo sustituido', function () {
    expect(EstadoComprobante::Aceptado->esValido())->toBeTrue()
        ->and(EstadoComprobante::Observado->esValido())->toBeTrue()
        ->and(EstadoComprobante::Sustituido->esValido())->toBeTrue()
        ->and(EstadoComprobante::Generado->esValido())->toBeFalse()
        ->and(EstadoComprobante::Rechazado->esValido())->toBeFalse()
        ->and(EstadoComprobante::Anulado->esValido())->toBeFalse();
});

it('impide saltarse pasos del ciclo de vida del comprobante', function () {
    // De borrador a aceptado no se puede: hay que generar y enviar primero.
    expect(EstadoComprobante::Borrador->puedeTransicionarA(EstadoComprobante::Aceptado))->toBeFalse()
        ->and(EstadoComprobante::Borrador->puedeTransicionarA(EstadoComprobante::Generado))->toBeTrue()
        ->and(EstadoComprobante::Enviado->puedeTransicionarA(EstadoComprobante::Aceptado))->toBeTrue();

    expect(fn () => EstadoComprobante::Borrador->asegurarTransicionA(EstadoComprobante::Aceptado))
        ->toThrow(DomainException::class, "No se puede pasar un comprobante de 'borrador' a 'aceptado'.");
});

it('deja reintentar el envío desde error conservando el número', function () {
    expect(EstadoComprobante::Error->enviable())->toBeTrue()
        ->and(EstadoComprobante::Generado->enviable())->toBeTrue()
        ->and(EstadoComprobante::Aceptado->enviable())->toBeFalse()
        ->and(EstadoComprobante::Error->puedeTransicionarA(EstadoComprobante::Enviado))->toBeTrue();
});

it('solo permite anular localmente lo que SUNAT todavía no aceptó', function () {
    expect(EstadoComprobante::Generado->anulableLocalmente())->toBeTrue()
        ->and(EstadoComprobante::Rechazado->anulableLocalmente())->toBeTrue()
        // Un comprobante aceptado exige comunicación de baja o nota de crédito.
        ->and(EstadoComprobante::Aceptado->anulableLocalmente())->toBeFalse();
});

it('no deja volver a un estado terminal', function () {
    expect(EstadoComprobante::Anulado->transicionesValidas())->toBe([])
        ->and(EstadoComprobante::Sustituido->transicionesValidas())->toBe([]);
});

it('arranca la serie de cada tipo con el prefijo que autoriza SUNAT', function () {
    expect(TipoComprobante::Factura->serieInicial())->toBe('F001')
        ->and(TipoComprobante::Boleta->serieInicial())->toBe('B001')
        ->and(TipoComprobante::NotaCredito->serieInicial())->toBe('BC01');
});

it('solo deja anular por baja a facturas y boletas, no a notas de crédito', function () {
    expect(TipoComprobante::anulables())->toContain(TipoComprobante::Factura, TipoComprobante::Boleta)
        ->and(TipoComprobante::anulables())->not->toContain(TipoComprobante::NotaCredito);

    // La nota de crédito se corrige emitting otra contra ella.
    expect(TipoComprobante::NotaCredito->requiereSeriePropia())->toBeFalse();
});

it('traduce los medios de pago del sistema a los códigos de SUNAT', function () {
    expect(CatalogoSunat::medioPago('efectivo'))->toBe('005')
        ->and(CatalogoSunat::medioPago('transferencia'))->toBe('003')
        ->and(CatalogoSunat::medioPago('yape'))->toBe('003')
        ->and(CatalogoSunat::medioPago('plin'))->toBe('003')
        // Un medio desconocido nunca debe romper la emisión.
        ->and(CatalogoSunat::medioPago('bitcoin'))->toBe('005');
});

it('rechaza un tipo de documento de receptor que no existe en SUNAT', function () {
    expect(CatalogoSunat::tipoDocumentoReceptor('dni'))->toBe('0')
        ->and(CatalogoSunat::tipoDocumentoReceptor('ruc'))->toBe('6')
        ->and(CatalogoSunat::tipoDocumentoReceptor('ce'))->toBe('4')
        ->and(CatalogoSunat::tipoDocumentoReceptor('pasaporte'))->toBe('7')
        ->and(fn () => CatalogoSunat::tipoDocumentoReceptor('licencia_de_conducir'))
        ->toThrow(DomainException::class);
});
