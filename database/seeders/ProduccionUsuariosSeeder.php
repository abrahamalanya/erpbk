<?php

namespace Database\Seeders;

use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class ProduccionUsuariosSeeder extends Seeder
{
    /**
     * Usuarios reales de CREDIMAS capturados en producción (ver
     * database/seeders/data/produccion_usuarios.php), para que un
     * migrate:fresh --seed en producción no los pierda. El usuario
     * "sistemas" conserva la misma contraseña que ya usa UserSeeder para
     * esa misma cuenta (mismo email); el resto arranca con su propio DNI
     * como contraseña, igual que se les entrega en producción.
     */
    public function run(): void
    {
        $usuarios = require $this->rutaDatos('produccion_usuarios.php');

        $creados = collect($usuarios)->mapWithKeys(fn (array $datos): array => [
            $datos['email'] => $this->crearUsuario($datos),
        ]);

        collect($usuarios)
            ->filter(fn (array $datos): bool => $datos['supervisor_email'] !== null)
            ->each(fn (array $datos) => $creados[$datos['email']]->update([
                'supervisor_id' => $creados[$datos['supervisor_email']]->id,
            ]));
    }

    /**
     * Estos archivos guardan PII real (nombres, DNI, teléfonos) y por eso
     * están en .gitignore: solo existen en el servidor de producción, no en
     * el repositorio. Si faltan, es porque no se subieron todavía.
     */
    private function rutaDatos(string $archivo): string
    {
        $ruta = database_path("seeders/data/{$archivo}");

        if (! file_exists($ruta)) {
            throw new RuntimeException("Falta el archivo de datos de producción: {$ruta}. Súbelo al servidor antes de sembrar.");
        }

        return $ruta;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function crearUsuario(array $datos): User
    {
        $empresa = $datos['empresa'] ? Empresa::where('nombre', $datos['empresa'])->firstOrFail() : null;
        $agencia = $datos['agencia'] ? Agencia::where('nombre', $datos['agencia'])->firstOrFail() : null;
        $password = in_array('sistemas', $datos['roles'], true) ? 'abrahamalanya' : $datos['dni'];

        $user = User::query()->firstOrCreate(['email' => $datos['email']], [
            'nombre' => $datos['nombre'],
            'apellido' => $datos['apellido'],
            'dni' => $datos['dni'],
            'telefono' => $datos['telefono'],
            'password' => bcrypt($password),
            'estado' => $datos['estado'],
            'empresa_id' => $empresa?->id,
            'agencia_id' => $agencia?->id,
        ]);

        $user->syncRoles($datos['roles']);

        return $user;
    }
}
