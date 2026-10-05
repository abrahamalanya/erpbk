<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);

    $this->empresa = Empresa::factory()->create();
    $this->agencia = Agencia::factory()->for($this->empresa)->create();
    $this->otraAgencia = Agencia::factory()->for($this->empresa)->create();

    $this->admin = User::factory()->forEmpresa($this->empresa)->create();
    $this->admin->assignRole('administrador_general');
});

/** Usuario activo con fecha de nacimiento, en la agencia indicada. */
function usuarioQueCumple(string $fechaNacimiento, Agencia $agencia, Empresa $empresa, string $rol = 'asesor'): User
{
    $user = User::factory()->forAgencia($agencia)->create([
        'empresa_id' => $empresa->id,
        'agencia_id' => $agencia->id,
        'dni' => fake()->unique()->numerify('########'),
        'fecha_nacimiento' => $fechaNacimiento,
        'estado' => 'activo',
    ]);
    $user->assignRole($rol);

    return $user;
}

it('lists the users whose birthday falls inside the range, ignoring the birth year', function () {
    // Nacen en 1985 y 1990: el rango es de este año, así que ambos cumplen.
    usuarioQueCumple('1985-10-05', $this->agencia, $this->empresa);
    usuarioQueCumple('1990-10-20', $this->agencia, $this->empresa);
    usuarioQueCumple('1992-11-02', $this->agencia, $this->empresa);

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson('/api/reportes/cumpleanos?desde=2026-10-01&hasta=2026-10-31')
        ->assertSuccessful()
        ->json('data');

    expect($data)->toHaveCount(2);

    $cumpleanios = collect($data)->pluck('fecha_nacimiento')->sort()->values()->all();
    expect($cumpleanios)->toBe(['1985-10-05', '1990-10-20']);
});

it('excludes a birthday outside the range', function () {
    usuarioQueCumple('1985-10-05', $this->agencia, $this->empresa);
    // Cumple en diciembre: fuera del rango de octubre.
    usuarioQueCumple('1990-12-10', $this->agencia, $this->empresa);

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson('/api/reportes/cumpleanos?desde=2026-10-01&hasta=2026-10-31')
        ->assertSuccessful()
        ->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['fecha_nacimiento'])->toBe('1985-10-05');
});

it('computes the age the user is about to turn and the days left', function () {
    $proximoMes = now()->addDays(10);
    $nacimiento = $proximoMes->copy()->subYears(30)->toDateString();

    $user = usuarioQueCumple($nacimiento, $this->agencia, $this->empresa);

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson('/api/reportes/cumpleanos?desde='.$proximoMes->toDateString().'&hasta='.$proximoMes->toDateString())
        ->assertSuccessful()
        ->json('data.0');

    expect($data['cumple_anios'])->toBe(30)
        ->and($data['dias_para_cumple'])->toBe(10)
        ->and($data['id'])->toBe($user->id);
});

it('marks a birthday happening today with 0 days left', function () {
    usuarioQueCumple(now()->subYears(40)->toDateString(), $this->agencia, $this->empresa);

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson('/api/reportes/cumpleanos?desde='.now()->toDateString().'&hasta='.now()->toDateString())
        ->assertSuccessful()
        ->json('data.0');

    expect($data['dias_para_cumple'])->toBe(0)
        ->and($data['cumple_anios'])->toBe(40);
});

it('computes next year age for a birthday that already passed this year', function () {
    // Cumple en enero, pero hoy es octubre: su próximo cumpleaños es en enero
    // del año que viene, donde va a cumplir 31 (no 30).
    $cumple = now()->copy()->subMonths(2)->startOfMonth()->addDays(3);
    $nacimiento = $cumple->copy()->subYears(30)->toDateString();

    usuarioQueCumple($nacimiento, $this->agencia, $this->empresa);

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson("/api/reportes/cumpleanos?desde={$cumple->toDateString()}&hasta={$cumple->toDateString()}")
        ->assertSuccessful()
        ->json('data.0');

    expect($data['cumple_anios'])->toBe(31)
        ->and($data['dias_para_cumple'])->toBeGreaterThan(60);
});

it('resolves a range that crosses the year boundary', function () {
    // 20-12 hasta 05-01: debe traer ambos extremos y no solo el tramo más cercano.
    usuarioQueCumple('1990-12-22', $this->agencia, $this->empresa);
    usuarioQueCumple('1990-01-03', $this->agencia, $this->empresa);
    usuarioQueCumple('1990-06-15', $this->agencia, $this->empresa);

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson('/api/reportes/cumpleanos?desde=2026-12-20&hasta=2027-01-05')
        ->assertSuccessful()
        ->json('data');

    expect($data)->toHaveCount(2);
});

it('filters by agencia', function () {
    usuarioQueCumple('1985-10-05', $this->agencia, $this->empresa);
    usuarioQueCumple('1985-10-05', $this->otraAgencia, $this->empresa);

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson("/api/reportes/cumpleanos?desde=2026-10-01&hasta=2026-10-31&agencia_id={$this->agencia->id}")
        ->assertSuccessful()
        ->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['agencia'])->toBe($this->agencia->nombre);
});

it('filters by a single usuario', function () {
    $uno = usuarioQueCumple('1985-10-05', $this->agencia, $this->empresa);
    usuarioQueCumple('1985-10-05', $this->agencia, $this->empresa);

    Sanctum::actingAs($this->admin, ['*']);

    $data = $this->getJson("/api/reportes/cumpleanos?desde=2026-10-01&hasta=2026-10-31&usuario_id={$uno->id}")
        ->assertSuccessful()
        ->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['id'])->toBe($uno->id);
});

it('excludes users without a fecha de nacimiento and inactive users', function () {
    User::factory()->forAgencia($this->agencia)->create([
        'empresa_id' => $this->empresa->id,
        'fecha_nacimiento' => null,
    ]);

    usuarioQueCumple('1985-10-05', $this->agencia, $this->empresa, 'asesor')->update(['estado' => 'inactivo']);

    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson('/api/reportes/cumpleanos?desde=2026-10-01&hasta=2026-10-31')
        ->assertSuccessful()
        ->assertJsonCount(0, 'data');
});

it('hides other agencias from administrador_agencia', function () {
    usuarioQueCumple('1985-10-05', $this->agencia, $this->empresa);
    usuarioQueCumple('1985-10-05', $this->otraAgencia, $this->empresa);

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');

    Sanctum::actingAs($adminAgencia, ['*']);

    $data = $this->getJson('/api/reportes/cumpleanos?desde=2026-10-01&hasta=2026-10-31')
        ->assertSuccessful()
        ->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['agencia'])->toBe($this->agencia->nombre);
});

it('does not let a filter widen what administrador_agencia can see', function () {
    usuarioQueCumple('1985-10-05', $this->otraAgencia, $this->empresa);

    $adminAgencia = User::factory()->forAgencia($this->agencia)->create();
    $adminAgencia->assignRole('administrador_agencia');

    Sanctum::actingAs($adminAgencia, ['*']);

    // Pide explícitamente la otra agencia: el scoping manda.
    $this->getJson("/api/reportes/cumpleanos?desde=2026-10-01&hasta=2026-10-31&agencia_id={$this->otraAgencia->id}")
        ->assertSuccessful()
        ->assertJsonCount(0, 'data');
});

it('requires the date range', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson('/api/reportes/cumpleanos')->assertUnprocessable()
        ->assertJsonValidationErrors(['desde', 'hasta']);

    $this->getJson('/api/reportes/cumpleanos?desde=2026-10-01')->assertUnprocessable()
        ->assertJsonValidationErrors('hasta');
});

it('rejects an inverted date range', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson('/api/reportes/cumpleanos?desde=2026-10-31&hasta=2026-10-01')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('hasta');
});

it('rejects filters that do not exist', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->getJson('/api/reportes/cumpleanos?desde=2026-10-01&hasta=2026-10-31&agencia_id=999999')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('agencia_id');

    $this->getJson('/api/reportes/cumpleanos?desde=2026-10-01&hasta=2026-10-31&usuario_id=999999')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('usuario_id');
});

it('denies access to a user without usuarios.ver', function () {
    $sinPermiso = User::factory()->forAgencia($this->agencia)->create();

    Sanctum::actingAs($sinPermiso, ['*']);

    $this->getJson('/api/reportes/cumpleanos?desde=2026-10-01&hasta=2026-10-31')->assertForbidden();
    $this->getJson('/api/reportes/cumpleanos/pdf?desde=2026-10-01&hasta=2026-10-31')->assertForbidden();
    $this->getJson('/api/reportes/cumpleanos/excel?desde=2026-10-01&hasta=2026-10-31')->assertForbidden();
});

it('exports the report to PDF', function () {
    usuarioQueCumple('1985-10-05', $this->agencia, $this->empresa);

    Sanctum::actingAs($this->admin, ['*']);

    $response = $this->get('/api/reportes/cumpleanos/pdf?desde=2026-10-01&hasta=2026-10-31');

    $response->assertOk()->assertHeader('content-type', 'application/pdf');

    // Pdf::stream() devuelve una respuesta streameada: el cuerpo solo existe si
    // se captura el buffer de salida, no con getContent()/streamedContent().
    ob_start();
    $response->sendContent();
    $pdf = ob_get_clean();

    expect($pdf)->toStartWith('%PDF');
});

it('exports the report to Excel', function () {
    usuarioQueCumple('1985-10-05', $this->agencia, $this->empresa);

    Sanctum::actingAs($this->admin, ['*']);

    $response = $this->get('/api/reportes/cumpleanos/excel?desde=2026-10-01&hasta=2026-10-31');

    $response->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    // Symfony sanitiza el nombre con acentos: emite la versión ASCII
    // ("cumpleanos") más la UTF-8 percent-encoded, así que se comprueba el
    // prefijo y la extensión en vez del nombre completo.
    expect($response->headers->get('content-disposition'))
        ->toContain('attachment')
        ->toContain('.xlsx');

    ob_start();
    $response->sendContent();
    $xlsx = ob_get_clean();

    // Un .xlsx es un ZIP: empieza con la firma "PK".
    expect($xlsx)->toStartWith('PK');
});

it('puts the report rows into the PDF export', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $rango = 'desde=2026-10-01&hasta=2026-10-31';

    ob_start();
    $this->get("/api/reportes/cumpleanos/pdf?{$rango}")->sendContent();
    $vacio = ob_get_clean();

    usuarioQueCumple('1985-10-05', $this->agencia, $this->empresa);
    usuarioQueCumple('1990-10-20', $this->agencia, $this->empresa);

    ob_start();
    $this->get("/api/reportes/cumpleanos/pdf?{$rango}")->sendContent();
    $conFilas = ob_get_clean();

    // El PDF no comprime la estructura de la tabla, así que más filas pesan
    // más: si las filas NO llegaran a la vista ambos PDFs serían idénticos.
    expect(strlen($conFilas))->toBeGreaterThan(strlen($vacio));
});

it('renders an empty report without failing on PDF or Excel', function () {
    Sanctum::actingAs($this->admin, ['*']);

    $this->get('/api/reportes/cumpleanos/pdf?desde=2026-10-01&hasta=2026-10-31')->assertOk();
    $this->get('/api/reportes/cumpleanos/excel?desde=2026-10-01&hasta=2026-10-31')->assertOk();
});
