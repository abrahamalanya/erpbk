<?php

namespace Database\Seeders;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Cliente\Models\ClienteFoto;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Ubigeo\Models\UbigeoDistrito;
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

            $cliente = Cliente::query()->firstOrCreate([
                'empresa_id' => $empresa->id,
                'numero_documento' => $datos['numero_documento'],
            ], [
                'agencia_id' => $agencia->id,
                'asesor_id' => $this->usuarioId($datos['asesor_email']),
                'registrado_por' => $this->usuarioId($datos['registrado_por_email']),
                'nombre' => $datos['nombre'],
                'apellido' => $datos['apellido'],
                'tipo_documento' => $datos['tipo_documento'],
                'fecha_nacimiento' => $datos['fecha_nacimiento'] ?? null,
                'sexo' => $datos['sexo'] ?? null,
                'estado_civil' => $datos['estado_civil'] ?? null,
                'email' => $datos['email'] ?? null,
                'telefono' => $datos['telefono'],
                'direccion' => $datos['direccion'],
                'ubigeo_distrito_id' => $this->resolverDistritoId($datos, 'ubigeo_distrito'),
                'referencia' => $datos['referencia'],
                'latitud' => $datos['latitud'] ?? null,
                'longitud' => $datos['longitud'] ?? null,
                'direccion_negocio' => $datos['direccion_negocio'] ?? null,
                'ubigeo_distrito_negocio_id' => $this->resolverDistritoId($datos, 'ubigeo_distrito_negocio'),
                'referencia_negocio' => $datos['referencia_negocio'] ?? null,
                'latitud_negocio' => $datos['latitud_negocio'] ?? null,
                'longitud_negocio' => $datos['longitud_negocio'] ?? null,
                'foto_cliente_path' => $datos['foto_cliente_path'],
                'foto_dni_path' => $datos['foto_dni_path'],
                'foto_dni_reverso_path' => $datos['foto_dni_reverso_path'],
                'estado' => $datos['estado'],
            ]);

            // Casa y negocio son fotos múltiples: van como filas en
            // cliente_fotos. firstOrCreate() es idempotente, así que solo se
            // agrega lo que todavía falta para no duplicar al re-correrlo.
            foreach ([ClienteFoto::TIPO_CASA, ClienteFoto::TIPO_NEGOCIO] as $tipo) {
                $path = $datos["foto_{$tipo}_path"] ?? null;

                if ($path && ! $cliente->fotos()->where('tipo', $tipo)->exists()) {
                    $cliente->fotos()->create(['tipo' => $tipo, 'path' => $path, 'orden' => 0]);
                }
            }
        }
    }

    /**
     * Resuelve un distrito por su código natural. El id solo se acepta si
     * corresponde a una fila existente en la instalación actual.
     *
     * @param  array<string, mixed>  $datos
     */
    private function resolverDistritoId(array $datos, string $prefijo): ?int
    {
        $codigo = $datos["{$prefijo}_codigo"] ?? null;

        if ($codigo !== null) {
            return UbigeoDistrito::query()->where('codigo', $codigo)->value('id');
        }

        $id = $datos["{$prefijo}_id"] ?? null;

        return $id !== null && UbigeoDistrito::query()->whereKey($id)->exists()
            ? (int) $id
            : null;
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
