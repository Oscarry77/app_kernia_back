<?php
namespace App\Services\Seguridad;

/**
 * Generador único de contraseñas seguras de Kernia (02-oct-2026).
 *
 * Garantiza por construcción al menos una mayúscula, una minúscula, un número
 * y un símbolo, con `random_int` (criptográficamente seguro) también al
 * mezclar. Excluye los caracteres que han dado problemas en MySQL, `.env` de
 * Laravel, shell, JSON o URLs:
 *
 *   ' " ` \  (delimitadores y escape en SQL, JSON y shell)
 *   $        (interpolación en .env y shell)
 *   #        (comentario en .env)
 *   % &      (comodín de SQL / URLs y shell)
 *   ; < > | ^ ~ ( ) [ ] { } / ? : , y espacio
 *
 * y los ambiguos a la vista (I, O, l, o, 0, 1).
 */
final class GeneradorPassword
{
    public const MAYUSCULAS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    public const MINUSCULAS = 'abcdefghijkmnpqrstuvwxyz';
    public const NUMEROS = '23456789';
    public const SIMBOLOS = '!*-_=+.@';

    public static function generar(int $longitud = 18): string
    {
        if ($longitud < 12) {
            throw new \InvalidArgumentException('La longitud mínima es 12.');
        }

        $clases = [self::MAYUSCULAS, self::MINUSCULAS, self::NUMEROS, self::SIMBOLOS];
        $todos = implode('', $clases);

        $caracteres = [];
        foreach ($clases as $clase) {
            $caracteres[] = $clase[random_int(0, strlen($clase) - 1)];
        }
        while (count($caracteres) < $longitud) {
            $caracteres[] = $todos[random_int(0, strlen($todos) - 1)];
        }

        // Fisher-Yates con random_int (shuffle() no es criptográfico).
        for ($i = count($caracteres) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$caracteres[$i], $caracteres[$j]] = [$caracteres[$j], $caracteres[$i]];
        }

        return implode('', $caracteres);
    }
}
