<?php

namespace Database\Seeders;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\CreditoHipotecario\Models\Inmueble;
use App\Modules\Empresa\Models\Agencia;
use App\Modules\Empresa\Models\Empresa;
use App\Modules\Usuario\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class ProduccionInmueblesSeeder extends Seeder
{
    /**
     * Inmuebles reales ya registrados en producción (ver
     * database/seeders/data/produccion_inmuebles.php). Debe correr después
     * de ProduccionClientesSeeder: cada inmueble se ata a su cliente por
     * número de documento, no por id. ubigeo_distrito_id no se replica (no
     * tiene una clave natural simple de resolver) — queda sin distrito.
     */
    public function run(): void
    {
        $inmuebles = require $this->rutaDatos('produccion_inmuebles.php');

        foreach ($inmuebles as $datos) {
            $empresa = Empresa::where('nombre', $datos['empresa'])->firstOrFail();
            $agencia = Agencia::where('nombre', $datos['agencia'])->firstOrFail();

            $cliente = Cliente::query()
                ->where('empresa_id', $empresa->id)
                ->where('numero_documento', $datos['cliente_numero_documento'])
                ->firstOrFail();

            // created_at entra a la clave porque un mismo cliente puede
            // tener 2 inmuebles distintos con la misma partida registral
            // capturada (ej. re-empeñado en fechas distintas) — sin esto,
            // firstOrCreate() los fusiona en uno solo y se pierde el otro.
            $inmueble = Inmueble::query()->firstOrCreate([
                'cliente_id' => $cliente->id,
                'partida_registral' => $datos['partida_registral'],
                'created_at' => $datos['created_at'],
            ], [
                'empresa_id' => $empresa->id,
                'agencia_id' => $agencia->id,
                'registrado_por' => $datos['registrado_por_email'] ? User::where('email', $datos['registrado_por_email'])->firstOrFail()->id : null,
                'oficina_registral' => $datos['oficina_registral'],
                'tipo_inmueble' => $datos['tipo_inmueble'],
                'direccion' => $datos['direccion'],
                'area_terreno' => $datos['area_terreno'],
                'area_construida' => $datos['area_construida'],
                'propietario' => $datos['propietario'],
                'con_gravamen' => $datos['con_gravamen'],
                'linderos' => $datos['linderos'],
                'observacion' => $datos['observacion'],
                'valorizacion' => $datos['valorizacion'],
                'precio_venta' => $datos['precio_venta'],
                'puntaje' => $datos['puntaje'],
                'foto_cliente_producto_path' => $datos['foto_cliente_producto_path'],
                'video_path' => $datos['video_path'],
                'estado' => $datos['estado'],
            ]);

            // DatabaseSeeder corre con WithoutModelEvents: el hook de
            // EsGarantia::bootEsGarantia() que asigna el código no se
            // dispara. Lo asignamos a mano, y con saveQuietly() fijamos
            // también el created_at real (el insert lo pisa con "now").
            if ($inmueble->wasRecentlyCreated) {
                $inmueble->forceFill([
                    'codigo' => 'I-'.str_pad((string) $inmueble->id, 6, '0', STR_PAD_LEFT),
                    'created_at' => $datos['created_at'],
                    'updated_at' => $datos['created_at'],
                ])->saveQuietly();
            }
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
