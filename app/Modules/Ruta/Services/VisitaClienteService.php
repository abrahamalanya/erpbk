<?php

namespace App\Modules\Ruta\Services;

use App\Modules\Cliente\Models\Cliente;
use App\Modules\Ruta\Models\VisitaCliente;
use App\Modules\Ubicacion\Models\UbicacionAsesor;
use App\Modules\Usuario\Models\User;
use App\Nucleo\Support\Geo;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Detecta y persiste la permanencia del asesor cerca del lugar de cobranza
 * de cada cliente de su ruta de hoy — la "fuente de verdad" en servidor que
 * decide si un cliente se marca amarillo en el mapa unificado. El frontend
 * nunca confirma una visita por sí mismo; solo lee lo que este servicio ya
 * guardó.
 *
 * Reglas (ver también config/mapa_cobranza.php):
 * - Solo cuenta un ping dentro de `radio_proximidad_metros` del lugar de
 *   cobranza elegido (Cliente::ubicacionCobranza() — nunca cae a la otra
 *   ubicación) y con precisión GPS mejor que `precision_maxima_metros`.
 * - La racha se extiende mientras los pings sean continuos (hueco entre
 *   lecturas <= `gap_maximo_segundos`); un hueco mayor es una interrupción
 *   real y reinicia la racha si todavía no se confirmó.
 * - Se confirma (amarillo) cuando la racha acumula
 *   `minutos_permanencia` minutos de reloj real entre `inicio_en` y la
 *   lectura que confirma — no por cantidad de pings.
 * - Salir del radio (o una lectura con precisión insuficiente) reinicia el
 *   contador si todavía no se confirmó; una vez confirmada, la fila no se
 *   toca más ese día (sticky), igual que el estado "cobrado hoy".
 */
final class VisitaClienteService
{
    public function __construct(private readonly RutaCobranzaService $rutaCobranzaService) {}

    public function procesarPing(User $asesor, UbicacionAsesor $ubicacion): void
    {
        $clientes = $this->rutaCobranzaService->clientesEnRutaHoy($asesor);

        if ($clientes->isEmpty()) {
            return;
        }

        $radio = (float) config('mapa_cobranza.radio_proximidad_metros');
        $precisionMaxima = (float) config('mapa_cobranza.precision_maxima_metros');
        $gapMaximo = (int) config('mapa_cobranza.gap_maximo_segundos');
        $minutosPermanencia = (int) config('mapa_cobranza.minutos_permanencia');

        $capturadoEn = $ubicacion->capturado_en;
        $fecha = $capturadoEn->copy()->timezone(config('app.timezone'))->toDateString();
        $precisionOk = $ubicacion->precision_metros !== null && $ubicacion->precision_metros <= $precisionMaxima;

        $existentes = VisitaCliente::query()
            ->where('asesor_id', $asesor->id)
            ->whereDate('fecha', $fecha)
            ->whereIn('cliente_id', $clientes->pluck('id'))
            ->get()
            ->keyBy('cliente_id');

        foreach ($clientes as $cliente) {
            $this->procesarClientePing($cliente, $asesor, $ubicacion, $existentes->get($cliente->id), $fecha, $capturadoEn, $radio, $precisionOk, $gapMaximo, $minutosPermanencia);
        }
    }

    private function procesarClientePing(
        Cliente $cliente,
        User $asesor,
        UbicacionAsesor $ubicacion,
        ?VisitaCliente $visita,
        string $fecha,
        Carbon $capturadoEn,
        float $radio,
        bool $precisionOk,
        int $gapMaximo,
        int $minutosPermanencia,
    ): void {
        $ubicacionCobranza = $cliente->ubicacionCobranza();

        // Sin coordenadas para el lugar elegido: no hay nada que evaluar, y
        // nunca se usa la otra ubicación como respaldo.
        if ($ubicacionCobranza['latitud'] === null || $ubicacionCobranza['longitud'] === null) {
            return;
        }

        $distancia = Geo::distanciaMetros(
            (float) $ubicacion->latitud,
            (float) $ubicacion->longitud,
            $ubicacionCobranza['latitud'],
            $ubicacionCobranza['longitud'],
        );

        $dentroDelRadio = $distancia <= $radio && $precisionOk;

        if (! $dentroDelRadio) {
            // Fuera de radio (o precisión insuficiente): reinicia si no
            // estaba confirmada; una visita ya confirmada no se deshace
            // porque el asesor se aleje.
            if ($visita && $visita->confirmada_en === null) {
                $visita->delete();
            }

            return;
        }

        // Ping fuera de orden (más viejo que la última lectura registrada):
        // no se usa para avanzar ni para reiniciar el contador.
        if ($visita && $visita->ultima_lectura_en !== null && $capturadoEn->lessThan($visita->ultima_lectura_en)) {
            return;
        }

        if (! $visita || $visita->ultima_lectura_en === null) {
            // $existentes (cargado en procesarPing, con whereDate) ya confirmó
            // que no hay fila para hoy — un create() directo evita el desajuste
            // entre el formato de fecha que graba el cast 'date' al escribir y
            // el que usaría un updateOrCreate()/firstOrCreate() para buscarla.
            VisitaCliente::query()->create([
                'cliente_id' => $cliente->id,
                'asesor_id' => $asesor->id,
                'fecha' => $fecha,
                'empresa_id' => $asesor->empresa_id,
                'agencia_id' => $asesor->agencia_id,
                'inicio_en' => $capturadoEn,
                'ultima_lectura_en' => $capturadoEn,
            ]);

            return;
        }

        $huecoSegundos = $visita->ultima_lectura_en->diffInSeconds($capturadoEn);

        if ($huecoSegundos > $gapMaximo) {
            // Interrupción real (no solo pocas lecturas): reinicia la
            // racha si no estaba confirmada; si ya lo estaba, se deja igual.
            if ($visita->confirmada_en === null) {
                $visita->update(['inicio_en' => $capturadoEn, 'ultima_lectura_en' => $capturadoEn]);
            }

            return;
        }

        $datos = ['ultima_lectura_en' => $capturadoEn];

        if ($visita->confirmada_en === null && $visita->inicio_en->diffInSeconds($capturadoEn) >= $minutosPermanencia * 60) {
            $datos['confirmada_en'] = $capturadoEn;
        }

        $visita->update($datos);
    }

    /**
     * Visitas de hoy de los asesores pedidos, agrupadas por asesor y luego
     * por cliente — una sola query para todo el mapa unificado, en vez de
     * una por cliente.
     *
     * @param  list<int>  $asesorIds
     * @return Collection<int, Collection<int, VisitaCliente>>
     */
    public function visitasDeHoyPorAsesor(array $asesorIds): Collection
    {
        if ($asesorIds === []) {
            return collect();
        }

        $hoy = now(config('app.timezone'))->toDateString();

        return VisitaCliente::query()
            ->whereIn('asesor_id', $asesorIds)
            ->whereDate('fecha', $hoy)
            ->get()
            ->groupBy('asesor_id')
            ->map(fn (Collection $visitas): Collection => $visitas->keyBy('cliente_id'));
    }
}
