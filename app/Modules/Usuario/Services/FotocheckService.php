<?php

namespace App\Modules\Usuario\Services;

use App\Modules\Usuario\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Arma los datos de cada tarjeta de fotocheck de un usuario: logo de la
 * empresa, foto de perfil y QR con su DNI, todos en data URI.
 *
 * Las imágenes van embebidas como data URI porque dompdf no resuelve rutas de
 * Storage por sí solo, y además las fotos reales de perfil llegan hasta 8MB
 * (ver StoreUserRequest): sin reescalar, varias tarjetas en una sola hoja
 * pasarían de varios MB. Ver imagenDataUri(), el mismo criterio que ya usa
 * DocumentoCreditoService para las fotos del expediente.
 */
final class FotocheckService
{
    /**
     * Ancho máximo al que se reescala la foto de perfil antes de embeberla.
     * Para una foto impresa de ~32mm a 300dpi sobran ~550px.
     */
    private const ANCHO_MAXIMO_FOTO = 550;

    /**
     * Ancho máximo del logo — se imprime mucho más chico que la foto.
     */
    private const ANCHO_MAXIMO_LOGO = 250;

    /**
     * Datos listos para la vista: un item por usuario, con logo, foto y QR ya
     * embebidos. Un usuario sin foto o sin DNI, o una empresa sin logo, NO se
     * descarta — devuelve null en ese campo y la vista dibuja el placeholder
     * (o directamente omite el bloque, para el logo).
     *
     * @param  list<User>  $usuarios
     * @return Collection<int, array<string, mixed>>
     */
    public function tarjetas(array $usuarios): Collection
    {
        return collect($usuarios)->map(fn (User $usuario): array => [
            'id' => $usuario->id,
            'nombre' => trim("{$usuario->nombre} {$usuario->apellido}"),
            'cargo' => $this->cargoLegible($usuario),
            'dni' => $usuario->dni,
            'agencia' => $usuario->agencia?->nombre,
            // La agencia manda; empresas.celular_cobranzas es el respaldo para
            // agencias que aún no cargaron su número (columna nueva).
            'telefono' => $usuario->agencia?->telefono ?? $usuario->empresa?->celular_cobranzas,
            'logo_data_uri' => $this->imagenDataUri($usuario->empresa?->logo_path, self::ANCHO_MAXIMO_LOGO),
            'foto_data_uri' => $this->imagenDataUri($usuario->foto_path, self::ANCHO_MAXIMO_FOTO),
            'qr_data_uri' => $this->qrDataUri($usuario->dni),
        ]);
    }

    /**
     * Cuántos usuarios entran por hoja A4 horizontal. Cada usuario imprime
     * DOS tarjetas de 5.4x8.5cm, una al lado de la otra (identidad | aviso +
     * QR) — no una sola tarjeta partida en dos mitades. Entran 2 usuarios por
     * fila (sus 4 tarjetas) x 2 filas = 4 usuarios (8 tarjetas) por hoja.
     */
    public const TARJETAS_POR_HOJA = 4;

    /**
     * Renderiza la hoja A4 horizontal y la streamea inline (nada se escribe
     * en disco), igual que PdfGeneratorService::renderizarDesdeVista().
     *
     * @param  Collection<int, array<string, mixed>>  $tarjetas
     */
    public function pdf(Collection $tarjetas): Response
    {
        return Pdf::loadView('usuario.fotocheck', [
            // Una tabla por hoja, no una sola tabla partida: dompdf no corta
            // tablas entre páginas de forma fiable y con varias tarjetas en
            // una sola tabla salían todas apiladas en la primera página.
            'hojas' => $tarjetas->chunk(self::TARJETAS_POR_HOJA),
            'porHoja' => self::TARJETAS_POR_HOJA,
        ])->stream();
    }

    /**
     * El cargo que se imprime en la tarjeta es uno solo. Un usuario puede
     * tener varios roles (un supervisor que además cobra, por ejemplo) y en
     * una tarjeta de identificación tiene que verse el más principal: el
     * primero según el orden de la jerarquía de roles del módulo Usuario.
     */
    private function cargoLegible(User $usuario): string
    {
        $orden = ['sistemas', 'administrador_general', 'administrador_agencia', 'secretaria', 'supervisor', 'asesor', 'peinadora'];

        $posicion = function (string $rol) use ($orden): int {
            $indice = array_search($rol, $orden, true);

            // Un rol fuera del catálogo va al final, sin romper el sort.
            return $indice === false ? PHP_INT_MAX : $indice;
        };

        $nombres = $usuario->getRoleNames()->all();

        usort($nombres, fn (string $a, string $b): int => $posicion($a) <=> $posicion($b));

        return $nombres === [] ? '—' : ucfirst($nombres[0]);
    }

    /**
     * Reescala y re-codifica una imagen (foto de perfil o logo de empresa)
     * como data URI para embeberla en el PDF. Null si no hay ruta, el
     * archivo no existe o no es JPEG/PNG — la vista cae al placeholder (o
     * directamente omite el bloque) en vez de romper la hoja entera.
     */
    private function imagenDataUri(?string $path, int $anchoMaximo): ?string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $absoluto = Storage::disk('public')->path($path);
        $info = @getimagesize($absoluto);

        if (! $info) {
            return null;
        }

        [$ancho, $alto, $tipo] = $info;

        $origen = match ($tipo) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($absoluto),
            IMAGETYPE_PNG => @imagecreatefrompng($absoluto),
            default => null,
        };

        if (! $origen) {
            return null;
        }

        $anchoFinal = min($ancho, $anchoMaximo);
        $altoFinal = max(1, (int) round($alto * ($anchoFinal / $ancho)));

        $destino = imagecreatetruecolor($anchoFinal, $altoFinal);

        // El PNG con transparencia se aplana contra blanco: dompdf la pinta
        // negra y la imagen saldría con un fondo raro en la tarjeta.
        imagefill($destino, 0, 0, imagecolorallocate($destino, 255, 255, 255));
        imagecopyresampled($destino, $origen, 0, 0, 0, 0, $anchoFinal, $altoFinal, $ancho, $alto);

        ob_start();
        imagejpeg($destino, null, 80);
        $binario = ob_get_clean();

        imagedestroy($origen);
        imagedestroy($destino);

        return 'data:image/jpeg;base64,'.base64_encode($binario);
    }

    /**
     * QR con el DNI en texto plano. Null si el usuario no tiene DNI: la vista
     * dibuja el espacio vacío en vez de un QR con contenido inventado.
     */
    private function qrDataUri(?string $dni): ?string
    {
        if (! $dni) {
            return null;
        }

        return (new PngWriter)->write(new QrCode(data: $dni, size: 300, margin: 2))->getDataUri();
    }
}
