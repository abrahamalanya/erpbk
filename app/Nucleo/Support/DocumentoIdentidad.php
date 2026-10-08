<?php

namespace App\Nucleo\Support;

use DomainException;

/**
 * Catálogo de documentos de identidad que el sistema reconoce, con la longitud
 * que exige cada uno y la validación del dígito verificador del RUC.
 *
 * Vive en el núcleo porque lo usan dos módulos: Cliente (para validar el
 * documento al registrar) y Facturacion (para el tipo de documento del
 * receptor en el XML y para decidir factura vs boleta).
 */
final class DocumentoIdentidad
{
    /**
     * Tipos de documento de identidad que acepta el sistema.
     *
     * `ruc` es el agregado para facturación: sin él es imposible emitir una
     * factura, porque toda factura electrónica exige RUC de receptor.
     *
     * @var array<string, int> tipo => longitud esperada
     */
    public const LONGITUD = [
        'dni' => 8,
        'ruc' => 11,
        'ce' => 9,
        'pasaporte' => 9,
    ];

    /**
     * Pesos del algoritmo módulo 11 que SUNAT usa para el dígito verificador
     * del RUC, aplicados a los primeros 10 dígitos.
     *
     * @var list<int>
     */
    private const PESOS_RUC = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    /**
     * Tipos de documento cuyo número es estrictamente numérico. El CE y el
     * pasaporte no: el CE es alfanumérico (X1234567A) y el pasaporte depende
     * del país emisor, así que solo se le exige un formato razonable.
     *
     * @var list<string>
     */
    private const SOLO_DIGITOS = ['dni', 'ruc'];

    /**
     * @return list<string>
     */
    public static function tipos(): array
    {
        return array_keys(self::LONGITUD);
    }

    public static function esValido(string $tipo): bool
    {
        return array_key_exists($tipo, self::LONGITUD);
    }

    public static function longitudDe(string $tipo): ?int
    {
        return self::LONGITUD[$tipo] ?? null;
    }

    /**
     * El RUC solo es válido si los 11 dígitos son numéricos y el último
     * coincide con el módulo 11 de SUNAT. Un RUC mal tecleado produce
     * comprobantes que SUNAT rechaza en el envío, no en el registro — que es
     * el peor momento para enterarse.
     */
    public static function rucEsValido(string $ruc): bool
    {
        if (! preg_match('/^\d{11}$/', $ruc)) {
            return false;
        }

        $digitos = array_map('intval', str_split($ruc));
        $verificador = array_pop($digitos);

        $suma = 0;
        foreach (self::PESOS_RUC as $indice => $peso) {
            $suma += $digitos[$indice] * $peso;
        }

        $resto = $suma % 11;
        $esperado = match (true) {
            $resto === 0 => 0,
            $resto === 1 => 1,
            default => 11 - $resto,
        };

        return $esperado === $verificador;
    }

    /**
     * Valida un documento contra el catálogo y su longitud. Para el RUC
     * comprueba además el dígito verificador.
     *
     * @throws DomainException con el motivo exacto, para que el mensaje en la
     *                         UI sea accionable ("son 11 dígitos", no "documento inválido").
     */
    public static function validar(string $tipo, string $numero): void
    {
        $longitud = self::longitudDe($tipo);

        if ($longitud === null) {
            throw new DomainException("El tipo de documento '{$tipo}' no es válido.");
        }

        $numero = trim($numero);

        if ($numero === '') {
            throw new DomainException('El número de documento es requerido.');
        }

        // El pasaporte cambia de formato según el país emisor: se acepta
        // alfanumérico de hasta 20 caracteres.
        if ($tipo === 'pasaporte') {
            if (! preg_match('/^[A-Za-z0-9]{1,20}$/', $numero)) {
                throw new DomainException('El número de pasaporte solo puede tener letras y dígitos.');
            }

            return;
        }

        if (in_array($tipo, self::SOLO_DIGITOS, true) && ! preg_match('/^\d+$/', $numero)) {
            throw new DomainException("El {$tipo} debe tener solo dígitos.");
        }

        if (strlen($numero) !== $longitud) {
            throw new DomainException("El {$tipo} debe tener {$longitud} caracteres.");
        }

        if ($tipo === 'ruc' && ! self::rucEsValido($numero)) {
            throw new DomainException('El RUC no es válido: el dígito verificador no coincide.');
        }
    }

    /**
     * Una factura exige RUC de receptor; una boleta admite DNI, CE o
     * pasaporte.
     */
    public static function comprobantePara(string $tipoDocumento): string
    {
        return $tipoDocumento === 'ruc' ? 'factura' : 'boleta';
    }
}
