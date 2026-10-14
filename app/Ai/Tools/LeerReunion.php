<?php

namespace App\Ai\Tools;

use App\Models\Reunion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Devuelve el acta y, si se pide, la transcripción de una reunión.
 */
class LeerReunion implements Tool
{
    private const MAX_CARACTERES_TRANSCRIPCION = 80000;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Devuelve el acta completa (acuerdos, pendientes, puntos tratados) de una reunión por su ID. '
            .'Con incluir_transcripcion=true también devuelve lo que se dijo, con minuto y hablante; '
            .'úsalo solo si el acta no alcanza para responder.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $reunion = Reunion::query()->with('resumen')->find($request->string('id')->toString());

        if (! $reunion) {
            return 'No existe una reunión con ese ID. Usa ConsultarReuniones para encontrarla.';
        }

        $partes = [
            'Título: '.($reunion->titulo ?: 'Sin título'),
            'Fecha: '.$reunion->iniciada_en->translatedFormat('l j \d\e F \d\e Y, H:i'),
            'Duración: '.intdiv((int) $reunion->duracion_segundos, 60).' minutos',
            'Acta: '.($reunion->resumen
                ? json_encode($reunion->resumen->contenido, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
                : 'Todavía no tiene acta.'),
        ];

        if ($request->boolean('incluir_transcripcion')) {
            $partes[] = "Transcripción:\n".Str::limit($reunion->transcripcionComoTexto(), self::MAX_CARACTERES_TRANSCRIPCION);
        }

        return implode("\n", $partes);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('ID de la reunión, obtenido con ConsultarReuniones.')->required(),
            'incluir_transcripcion' => $schema->boolean()->required(),
        ];
    }
}
