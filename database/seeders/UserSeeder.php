<?php

namespace Database\Seeders;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
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
        ], 'sistemas');

        $sistema->assignRole('sistemas');

        $principal = Empresa::where('nombre', 'Empresa Principal')->firstOrFail();
        $secundaria = Empresa::where('nombre', 'Empresa Secundaria')->firstOrFail();

        // ===== Empresa Principal: nivel empresa =====
        $this->createUser([
            'nombre' => 'Admin',
            'apellido' => 'Sistema',
            'email' => 'admin.abrahamalanya@laravel.com',
            'empresa_id' => $principal->id,
        ], 'administrador_general');

        $this->createUser([
            'nombre' => 'Gestor',
            'apellido' => 'Créditos',
            'email' => 'gestor.abrahamalanya@laravel.com',
            'empresa_id' => $principal->id,
        ], 'secretaria');

        // ===== Empresa Principal: agencias =====
        $agenciaLima = Agencia::where('nombre', 'Agencia Lima')->firstOrFail();
        $this->seedAgencia($agenciaLima, 'Lima', [
            'nombre' => 'Ejecutivo',
            'apellido' => 'Ventas',
            'email' => 'ejecutivo.abrahamalanya@laravel.com',
        ]);

        $agenciaArequipa = Agencia::where('nombre', 'Agencia Arequipa')->firstOrFail();
        $this->seedAgencia($agenciaArequipa, 'Arequipa');

        $agenciaTrujillo = Agencia::where('nombre', 'Agencia Trujillo')->firstOrFail();
        $this->seedAgencia($agenciaTrujillo, 'Trujillo');

        // ===== Empresa Secundaria =====
        $this->createUser([
            'nombre' => 'Admin',
            'apellido' => 'Secundaria',
            'email' => 'admin.secundaria@laravel.com',
            'empresa_id' => $secundaria->id,
        ], 'administrador_general');

        $agenciaCusco = Agencia::where('nombre', 'Agencia Cusco')->firstOrFail();
        $this->seedAgencia($agenciaCusco, 'Cusco', asesores: 2, conPeinadora: false);
    }

    /**
     * Create a hierarchy user with the given role assigned.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createUser(array $attributes, string $role): User
    {
        $user = User::create([
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
        ], 'administrador_agencia');

        if ($conPeinadora) {
            $this->createUser([
                ...$base,
                'nombre' => 'Peinadora',
                'apellido' => $slug,
                'email' => "peinadora.{$slug}@laravel.com",
            ], 'peinadora');
        }

        $supervisor = $this->createUser([
            ...$base,
            'nombre' => 'Supervisor',
            'apellido' => $slug,
            'email' => "supervisor.{$slug}@laravel.com",
        ], 'supervisor');

        foreach (range(1, $asesores) as $n) {
            $this->createUser([
                ...$base,
                'nombre' => "Asesor {$n}",
                'apellido' => $slug,
                'email' => "asesor{$n}.{$slug}@laravel.com",
                'supervisor_id' => $supervisor->id,
            ], 'asesor');
        }
    }
}
