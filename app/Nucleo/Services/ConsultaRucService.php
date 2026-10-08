<?php

namespace App\Nucleo\Services;

use App\Nucleo\Support\DocumentoIdentidad;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Consulta un RUC contra la API de ConsultasPerú y lo normaliza al esquema
 * del sistema. Mismo patrón que ConsultaDniService (endpoint en `const`,
 * secreto en `config()`, `Http::` con timeout, `DomainException` con mensaje
 * en español).
 *
 * A diferencia del DNI, la respuesta trae la **razón social** y el nombre
 * completo del contribuyente, que es lo que necesita el comprobante: para un
 * cliente con RUC la factura va a nombre de la empresa, no de una persona.
 */
class ConsultaRucService
{
    private const ENDPOINT = 'https://api.consultasperu.com/api/v1/query';

    /**
     * @return array{
     *     numero_documento: string,
     *     nombre: string,
     *     apellido: string,
     *     direccion: ?string,
     *     estado: ?string
     * }
     */
    public function consultar(string $ruc): array
    {
        // El dígito verificador se comprueba antes de gastar una llamada: un
        // RUC mal tecleado casi siempre se detecta sin salir a la red.
        if (! preg_match('/^\d{11}$/', $ruc)) {
            throw new DomainException('El RUC debe tener 11 dígitos.');
        }

        if (! DocumentoIdentidad::rucEsValido($ruc)) {
            throw new DomainException('El RUC no es válido: el dígito verificador no coincide.');
        }

        $token = config('services.consultasperu.token');

        if (! $token) {
            throw new DomainException('CONSULTASPERU_TOKEN no está configurado.');
        }

        try {
            $response = Http::timeout(10)->post(self::ENDPOINT, [
                'token' => $token,
                'type_document' => 'ruc',
                'document_number' => $ruc,
            ]);
        } catch (ConnectionException) {
            throw new DomainException('No se pudo conectar con el servicio de consulta de RUC.');
        }

        $body = $response->json();

        if (! ($body['success'] ?? false)) {
            $message = match ($response->status()) {
                401 => 'Token de ConsultasPerú inválido. Revisa la configuración.',
                404 => 'No se encontró información para este RUC.',
                default => $body['message'] ?? 'No se pudo consultar el RUC.',
            };

            throw new DomainException($message);
        }

        $data = $body['data'] ?? [];

        $direccion = collect([
            $data['address'] ?? null,
            $data['district'] ?? null,
            $data['province'] ?? null,
            $data['department'] ?? null,
        ])->filter()->implode(', ');

        return [
            'numero_documento' => $data['number'] ?? $ruc,
            'nombre' => $data['name'] ?? '',
            'apellido' => $data['surname'] ?? '',
            'direccion' => $direccion !== '' ? $direccion : null,
            'estado' => $data['status'] ?? null,
        ];
    }
}
