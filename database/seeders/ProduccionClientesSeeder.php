<?php

namespace Database\Seeders;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class ProduccionClientesSeeder extends Seeder
{
    /**
     * Clientes reales ya registrados en producción (ver
     * database/seeders/data/produccion_clientes.php). Empresa, agencia y
     * usuarios se resuelven por su clave natural (nombre/email), no por id,
     * para no depender de que los autoincrementales calcen tras un
     * migrate:fresh.
     */
    public function run(): void
    {
        $clientes = require $this->rutaDatos('produccion_clientes.php');

        foreach ($clientes as $datos) {
            $empresa = Empresa::where('nombre', $datos['empresa'])->firstOrFail();
            $agencia = Agencia::where('nombre', $datos['agencia'])->firstOrFail();

            Cliente::query()->firstOrCreate([
                'empresa_id' => $empresa->id,
                'numero_documento' => $datos['numero_documento'],
            ], [
                'agencia_id' => $agencia->id,
                'asesor_id' => $this->usuarioId($datos['asesor_email']),
                'registrado_por' => $this->usuarioId($datos['registrado_por_email']),
                'nombre' => $datos['nombre'],
                'apellido' => $datos['apellido'],
                'tipo_documento' => $datos['tipo_documento'],
                'telefono' => $datos['telefono'],
                'direccion' => $datos['direccion'],
                'referencia' => $datos['referencia'],
                'foto_cliente_path' => $datos['foto_cliente_path'],
                'foto_dni_path' => $datos['foto_dni_path'],
                'foto_dni_reverso_path' => $datos['foto_dni_reverso_path'],
                'foto_casa_path' => $datos['foto_casa_path'],
                'foto_negocio_path' => $datos['foto_negocio_path'],
                'estado' => $datos['estado'],
            ]);
        }
    }

    private function usuarioId(?string $email): ?int
    {
        return $email ? User::where('email', $email)->firstOrFail()->id : null;
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
}
