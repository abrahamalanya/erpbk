<?php

namespace Database\Seeders;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoPrendario\Models\Bien;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class ProduccionBienesSeeder extends Seeder
{
    /**
     * Bienes reales ya registrados en producción (ver
     * database/seeders/data/produccion_bienes.php). Debe correr después de
     * ProduccionClientesSeeder: cada bien se ata a su cliente por número de
     * documento, no por id.
     */
    public function run(): void
    {
        $bienes = require $this->rutaDatos('produccion_bienes.php');

        foreach ($bienes as $datos) {
            $empresa = Empresa::where('nombre', $datos['empresa'])->firstOrFail();
            $agencia = Agencia::where('nombre', $datos['agencia'])->firstOrFail();

            $cliente = Cliente::query()
                ->where('empresa_id', $empresa->id)
                ->where('numero_documento', $datos['cliente_numero_documento'])
                ->firstOrFail();

            Bien::query()->firstOrCreate([
                'cliente_id' => $cliente->id,
                'nombre' => $datos['nombre'],
                'serie' => $datos['serie'],
            ], [
                'empresa_id' => $empresa->id,
                'agencia_id' => $agencia->id,
                'registrado_por' => $datos['registrado_por_email'] ? User::where('email', $datos['registrado_por_email'])->firstOrFail()->id : null,
                'tipo' => $datos['tipo'],
                'marca' => $datos['marca'],
                'modelo' => $datos['modelo'],
                'observacion' => $datos['observacion'],
                'valorizacion' => $datos['valorizacion'],
                'precio_venta' => $datos['precio_venta'],
                'puntaje' => $datos['puntaje'],
                'foto_cliente_producto_path' => $datos['foto_cliente_producto_path'],
                'video_path' => $datos['video_path'],
                'estado' => $datos['estado'],
            ]);
        }
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
