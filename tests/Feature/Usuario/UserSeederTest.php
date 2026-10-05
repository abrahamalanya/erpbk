<?php

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Database\Seeders\AgenciaSeeder;
use Database\Seeders\EmpresaSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UbigeoSeeder;
use Database\Seeders\UserSeeder;

beforeEach(function () {
    // EmpresaSeeder/AgenciaSeeder solo crean la Empresa/Agencia de demostración
    // cuando el entorno es "local", pero phpunit corre con APP_ENV=testing. Sin
    // esto, UserSeeder reventaría en el firstOrFail() de "Empresa Secundaria".
    $this->app['env'] = 'local';

    $this->seed([RoleSeeder::class, PermissionSeeder::class, EmpresaSeeder::class, AgenciaSeeder::class, UbigeoSeeder::class]);
    $this->seed(UserSeeder::class);

    $this->usuarios = User::query()->with(['roles', 'agencia', 'ubigeoDistrito.provincia.departamento'])->get();
});

it('rellena contacto y ubicacion en todos los usuarios', function () {
    expect($this->usuarios)->not->toBeEmpty();

    foreach ($this->usuarios as $usuario) {
        expect($usuario->telefono)->toBeString()->not->toBeEmpty()
            ->and($usuario->fecha_nacimiento)->not->toBeNull()
            ->and($usuario->dni)->toBeString()->not->toBeEmpty()
            ->and($usuario->direccion)->toBeString()->not->toBeEmpty()
            ->and($usuario->referencia)->toBeString()->not->toBeEmpty()
            ->and($usuario->ubigeo_distrito_id)->toBeInt()
            ->and((float) $usuario->latitud)->not->toBe(0.0)
            ->and((float) $usuario->longitud)->not->toBe(0.0);
    }
});

it('genera un dni unico de 8 digitos', function () {
    $dnis = $this->usuarios->pluck('dni');

    // users.dni es UNIQUE: si el generador se repitiese, el seed reventaría.
    expect($dnis->unique())->toHaveCount($dnis->count());

    foreach ($dnis as $dni) {
        expect($dni)->toMatch('/^\d{8}$/');
    }
});

it('genera un telefono peruano de 9 digitos', function () {
    foreach ($this->usuarios as $usuario) {
        expect($usuario->telefono)->toMatch('/^9\d{8}$/');
    }
});

it('ubica a cada usuario en el distrito de su agencia', function () {
    foreach ($this->usuarios->whereNotNull('agencia_id')->groupBy('agencia_id') as $usuariosDeAgencia) {
        $distritos = $usuariosDeAgencia->pluck('ubigeo_distrito_id')->unique();

        // Un solo distrito por agencia: si se mezclaran, el mapa y el reporte
        // por ubigeo quedarían incoherentes.
        expect($distritos)->toHaveCount(1);
    }
});

it('expone departamento, provincia y distrito derivados del catalogo', function () {
    $usuario = $this->usuarios->whereNotNull('ubigeo_distrito_id')->first();

    // El departamento del Cusco se resuelve por la cadena completa del catalogo
    // INEI, no por coincidencias de nombre (hay distritos homonimos).
    $cusco = $this->usuarios->firstWhere('departamento', 'Cusco');
    expect($cusco)->not->toBeNull()
        ->and($cusco->provincia)->toBe('Cusco')
        ->and($cusco->distrito)->toBe('Cusco');

    $lima = $this->usuarios->firstWhere('departamento', 'Lima');
    expect($lima->provincia)->toBe('Lima')
        ->and($lima->distrito)->toBe('Lima');
});

it('situa las coordenadas dentro del Peru y agrupadas por agencia', function () {
    foreach ($this->usuarios as $usuario) {
        $lat = (float) $usuario->latitud;
        $lng = (float) $usuario->longitud;

        // Bounding box del Peru (con margen).
        expect($lat)->toBeGreaterThan(-19.0)->toBeLessThan(-2.0)
            ->and($lng)->toBeGreaterThan(-82.0)->toBeLessThan(-67.0);
    }

    // Los usuarios de una misma agencia rondan el mismo punto: si el seed
    // tirara coordenadas al azar por todo el pais, el mapa los veria
    // dispersos y las fotos de perfil no tendrian sentido.
    foreach ($this->usuarios->whereNotNull('agencia_id')->groupBy('agencia_id') as $grupo) {
        $lats = $grupo->map(fn (User $u): float => (float) $u->latitud);
        $lngs = $grupo->map(fn (User $u): float => (float) $u->longitud);

        expect($lats->max() - $lats->min())->toBeLessThan(0.05)
            ->and($lngs->max() - $lngs->min())->toBeLessThan(0.05);
    }
});

it('nacela fecha de nacimiento dentro de una edad laboral', function () {
    foreach ($this->usuarios as $usuario) {
        $edad = $usuario->fecha_nacimiento->diffInYears(now());

        expect($edad)->toBeGreaterThanOrEqual(24)->toBeLessThanOrEqual(59);
    }
});

it('coloca el telefono en cada agencia para el aviso de la fotocheck', function () {
    // Sin esto la fotocheck cae al celular de cobranzas de la empresa.
    foreach (Agencia::query()->get() as $agencia) {
        expect($agencia->telefono)->toBeString()->not->toBeEmpty();
    }

    expect(Agencia::where('nombre', 'Agencia Alameda')->first()->telefono)->toBe('511234567');
});

it('mantiene el organigrama de roles del seed', function () {
    expect($this->usuarios->count())->toBe(14);

    $porRol = $this->usuarios
        ->flatMap(fn (User $u): array => $u->getRoleNames()->all())
        ->countBy();

    expect($porRol->toArray())->toMatchArray([
        'sistemas' => 1,
        'administrador_general' => 2,
        'administrador_agencia' => 2,
        'secretaria' => 1,
        'supervisor' => 2,
        'peinadora' => 1,
        'asesor' => 5,
    ]);
});

it('deja activa a toda la gente para que los reportes por estado los vean', function () {
    // El reporte de cumpleaños y la fotocheck filtran estado=activo.
    expect($this->usuarios->where('estado', 'activo')->count())->toBe($this->usuarios->count());
});

it('no crea el usuario sistemas dentro de ninguna empresa', function () {
    $sistemas = $this->usuarios->firstWhere('email', 'abrahamalanya@laravel.com');

    // Cruza todas las empresas: no debe colarse empresa_id ni agencia_id.
    expect($sistemas->empresa_id)->toBeNull()
        ->and($sistemas->agencia_id)->toBeNull()
        ->and($sistemas->getRoleNames()->all())->toBe(['sistemas']);
});

it('respeta el aislamiento multi-empresa del organigrama', function () {
    $secundaria = Empresa::where('nombre', 'Empresa Secundaria')->firstOrFail();

    $deLaSecundaria = $this->usuarios->where('empresa_id', $secundaria->id);

    // Admin de la empresa (1) + agencia Cusco sin peinadora (admin, supervisor
    // y 2 asesores).
    expect($deLaSecundaria)->toHaveCount(5)
        ->and($deLaSecundaria->whereNotNull('agencia_id')->pluck('agencia_id')->unique())
        ->toHaveCount(1);

    // Todos los de la secundaria en Cusco, ninguno en Lima.
    expect($deLaSecundaria->pluck('departamento')->unique()->all())->toBe(['Cusco']);
});
