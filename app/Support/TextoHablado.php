<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Convierte el Markdown de una respuesta de Jarvis en texto para leer en voz
 * alta: sin asteriscos, almohadillas ni direcciones web, y con una pausa al
 * final de cada título o viñeta.
 */
class TextoHablado
{
    public static function desdeMarkdown(string $markdown, int $maxCaracteres): string
    {
        $lineas = collect(preg_split('/\R/', $markdown))
            ->map(function (string $linea) {
                $linea = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $linea);
                $linea = preg_replace('#https?://\S+#', '', $linea);
                $linea = preg_replace('/^\s*(#{1,6}|[-*•]|\d+[.)])\s+/', '', $linea);
                $linea = trim(str_replace(['**', '__', '`'], '', $linea));

                // Sin puntuación al final, la voz encadena el título con lo que sigue.
                return $linea !== '' && ! preg_match('/[.:;!?…]$/u', $linea) ? "{$linea}." : $linea;
            })
            ->filter(fn (string $linea) => $linea !== '');

        $texto = $lineas->implode("\n");

        if (mb_strlen($texto) <= $maxCaracteres) {
            return $texto;
        }

        $aviso = ' El resto de la respuesta está en pantalla.';
        $recorte = mb_substr($texto, 0, $maxCaracteres - mb_strlen($aviso));
        // Se corta al final de la última oración completa para no dejar una palabra a medias.
        $fin = max(mb_strrpos($recorte, '.') ?: 0, mb_strrpos($recorte, "\n") ?: 0);

        return Str::of($fin > 0 ? mb_substr($recorte, 0, $fin + 1) : $recorte)->trim()->append($aviso)->toString();
    }
}
