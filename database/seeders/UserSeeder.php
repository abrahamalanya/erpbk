<?php

namespace Database\Seeders;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Ubigeo\Models\UbigeoDepartamento;
use App\Modules\Ubigeo\Models\UbigeoDistrito;
use App\Modules\Ubigeo\Models\UbigeoProvincia;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Seeder;

/**
 * Usuarios de demostración (solo local, ver DatabaseSeeder). Además del organigrama,
 * cada usuario queda con datos de contacto y de ubicación para que el reporte de
 * cumpleaños, la fotocheck y el mapa tengan con qué trabajar sin cargar nada a mano.
 */
class UserSeeder extends Seeder
{
    /**
     * Ubicaciones reales (departamento/provincia/distrito del catálogo INEI) con
     * el punto central del distrito y unas calles típicas. Los tres niveles se
     * resuelven contra la cadena completa porque hay nombres repetidos en
     * distintas provincias.
     *
     * @var array<string, array{departamento: string, provincia: string, distrito: string, latitud: float, longitud: float, calles: list<string>}>
     */
    private const UBIGEOS = [
        'lima' => [
            'departamento' => 'Lima',
            'provincia' => 'Lima',
            'distrito' => 'Lima',
            'latitud' => -12.1288,
            'longitud' => -77.0294,
            'calles' => ['Av. Javier Prado Este', 'Av. La República', 'Calle Las Begonias', 'Av. Arequipa', 'Jr. Larco'],
        ],
        'cusco' => [
            'departamento' => 'Cusco',
            'provincia' => 'Cusco',
            'distrito' => 'Cusco',
            'latitud' => -13.5320,
            'longitud' => -71.9675,
            'calles' => ['Av. El Sol', 'Calle Hatun Rumiyoc', 'Av. Vargas', 'Calle Triunfo', 'Calle Palacio'],
        ],
    ];

    /**
     * @var list<string>
     */
    private const REFERENCIAS = [
        'Frente al parque central',
        'A dos cuadras de la plaza de armas',
        'Junto al mercado municipal',
        'Al lado del banco principal',
        'Cerca de la avenida principal',
        'Edificio de oficinas, 3er piso',
    ];

    /**
     * Base del DNI sintético: se incrementa por usuario para garantizar
     * unicidad (users.dni es UNIQUE) sin depender del azar.
     */
    private int $dniSecuencia = 45000000;

    /**
     * Cache de clave => ubigeo_distrito_id, para no repetir la cadena de tres
     * queries por usuario.
     *
     * @var array<string, int>
     */
    private array $distritosResueltos = [];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Usuario de sistema: acceso total, cruza todas las empresas
        $sistema = $this->createUser([
            'nombre' => 'Abraham',
            'apellido' => 'Alanya',
            'email' => 'abrahamalanya@laravel.com',
        ], 'sistemas', 'lima');

        $sistema->assignRole('sistemas');

        $principal = Empresa::where('nombre', 'CREDIMAS')->firstOrFail();
        $secundaria = Empresa::where('nombre', 'Empresa Secundaria')->firstOrFail();

        // ===== CREDIMAS: nivel empresa =====
        $this->createUser([
            'nombre' => 'Admin',
            'apellido' => 'Sistema',
            'email' => 'admin.abrahamalanya@laravel.com',
            'empresa_id' => $principal->id,
        ], 'administrador_general', 'lima');

        $this->createUser([
            'nombre' => 'Gestor',
            'apellido' => 'Créditos',
            'email' => 'gestor.abrahamalanya@laravel.com',
            'empresa_id' => $principal->id,
        ], 'secretaria', 'lima');

        // ===== CREDIMAS: agencias =====
        $agenciaAlameda = Agencia::where('nombre', 'Agencia Alameda')->firstOrFail();
        $this->seedAgencia($agenciaAlameda, 'Alameda', [
            'nombre' => 'Ejecutivo',
            'apellido' => 'Ventas',
            'email' => 'ejecutivo.abrahamalanya@laravel.com',
        ], lugar: 'lima');

        // ===== Empresa Secundaria =====
        $this->createUser([
            'nombre' => 'Admin',
            'apellido' => 'Secundaria',
            'email' => 'admin.secundaria@laravel.com',
            'empresa_id' => $secundaria->id,
        ], 'administrador_general', 'cusco');

        $agenciaCusco = Agencia::where('nombre', 'Agencia Cusco')->firstOrFail();
        $this->seedAgencia($agenciaCusco, 'Cusco', asesores: 2, conPeinadora: false, lugar: 'cusco');
    }

    /**
     * Create a hierarchy user with the given role assigned.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createUser(array $attributes, string $role, ?string $lugar = null): User
    {
        $user = User::create([
            ...$this->perfil($lugar ?? 'lima'),
            ...$attributes,
            'password' => bcrypt('abrahamalanya'),
            'estado' => 'activo',
        ]);

        $user->assignRole($role);

        return $user;
    }

    /**
     * Seed the full org chart for a single agencia: administrador_agencia,
     * peinadora, supervisor and its asesores.
     *
     * @param  array<string, mixed>|null  $administradorAttributes
     */
    private function seedAgencia(
        Agencia $agencia,
        string $slug,
        ?array $administradorAttributes = null,
        int $asesores = 3,
        bool $conPeinadora = true,
        string $lugar = 'lima',
    ): void {
        $base = [
            'empresa_id' => $agencia->empresa_id,
            'agencia_id' => $agencia->id,
        ];

        $this->createUser([
            ...$base,
            'nombre' => 'Administrador',
            'apellido' => $slug,
            'email' => "admin.{$slug}@laravel.com",
            ...$administradorAttributes ?? [],
        ], 'administrador_agencia', $lugar);

        if ($conPeinadora) {
            $this->createUser([
                ...$base,
                'nombre' => 'Peinadora',
                'apellido' => $slug,
                'email' => "peinadora.{$slug}@laravel.com",
            ], 'peinadora', $lugar);
        }

        $supervisor = $this->createUser([
            ...$base,
            'nombre' => 'Supervisor',
            'apellido' => $slug,
            'email' => "supervisor.{$slug}@laravel.com",
        ], 'supervisor', $lugar);

        foreach (range(1, $asesores) as $n) {
            $this->createUser([
                ...$base,
                'nombre' => "Asesor {$n}",
                'apellido' => $slug,
                'email' => "asesor{$n}.{$slug}@laravel.com",
                'supervisor_id' => $supervisor->id,
            ], 'asesor', $lugar);
        }
    }

    /**
     * Datos de contacto y de ubicación de un usuario, coherentes con el
     * distrito de la agencia: el dni es único, las coordenadas rondan el centro
     * del distrito (con un desvío para que no se apilen en el mismo punto) y el
     * ubigeo apunta a la fila real del catálogo.
     *
     * @return array<string, mixed>
     */
    private function perfil(string $lugar): array
    {
        $ubigeo = self::UBIGEOS[$lugar];

        return [
            'telefono' => fake()->numerify('9########'),
            'fecha_nacimiento' => fake()->dateTimeBetween('-58 years', '-24 years')->format('Y-m-d'),
            'dni' => (string) ++$this->dniSecuencia,
            'direccion' => sprintf('%s %d', fake()->randomElement($ubigeo['calles']), fake()->numberBetween(100, 1999)),
            'referencia' => fake()->randomElement(self::REFERENCIAS),
            'ubigeo_distrito_id' => $this->distritoId($lugar),
            'latitud' => fake()->latitude($ubigeo['latitud'] - 0.012, $ubigeo['latitud'] + 0.012),
            'longitud' => fake()->longitude($ubigeo['longitud'] - 0.012, $ubigeo['longitud'] + 0.012),
        ];
    }

    /**
     * Resuelve el distrito por la cadena completa departamento → provincia →
     * distrito y cachea el id. firstOrFail a propósito: si el catálogo de
     * ubigeo cambia, el seeder debe romperse en vez de dejar la ubicación en
     * null sin avisar.
     */
    private function distritoId(string $lugar): int
    {
        return $this->distritosResueltos[$lugar] ??= $this->resolverDistrito(self::UBIGEOS[$lugar]);
    }

    /**
     * @param  array{departamento: string, provincia: string, distrito: string}  $ubigeo
     */
    private function resolverDistrito(array $ubigeo): int
    {
        $departamento = UbigeoDepartamento::query()
            ->where('nombre', $ubigeo['departamento'])
            ->firstOrFail();

        $provincia = UbigeoProvincia::query()
            ->where('ubigeo_departamento_id', $departamento->id)
            ->where('nombre', $ubigeo['provincia'])
            ->firstOrFail();

        return UbigeoDistrito::query()
            ->where('ubigeo_provincia_id', $provincia->id)
            ->where('nombre', $ubigeo['distrito'])
            ->firstOrFail()
            ->id;
    }
}
