<?php

namespace App\Ai\Tools;

use App\Enums\EstadoReunion;
use App\Models\Reunion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Busca reuniones grabadas por fecha o por palabras de su título o resumen.
 */
class ConsultarReuniones implements Tool
{
    private const LIMITE = 15;

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Busca reuniones del directorio grabadas con Jarvis. Filtra por rango de fechas y/o por '
            .'palabras que aparezcan en el título o el resumen. Devuelve el ID, título, fecha y resumen '
            .'ejecutivo de cada una. Para ver el acta completa o la transcripción usa LeerReunion.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $desde = $request->string('desde')->toString();
        $hasta = $request->string('hasta')->toString();
        $texto = $request->string('texto')->trim()->toString();

        $reuniones = Reunion::query()
            ->where('estado', EstadoReunion::Completada)
            ->with('resumen')
            ->when($desde, fn ($query) => $query->where('iniciada_en', '>=', Carbon::parse($desde)->startOfDay()))
            ->when($hasta, fn ($query) => $query->where('iniciada_en', '<=', Carbon::parse($hasta)->endOfDay()))
            ->when($texto, fn ($query) => $query->where(fn ($query) => $query
                ->where('titulo', 'like', "%{$texto}%")
                ->orWhereHas('resumenes', fn ($query) => $query->where('contenido', 'like', "%{$texto}%"))))
            ->latest('iniciada_en')
            ->limit(self::LIMITE)
            ->get();

        if ($reuniones->isEmpty()) {
            return 'No se encontraron reuniones con esos criterios.';
        }

        return $reuniones->map(fn (Reunion $reunion) => implode("\n", [
            "ID: {$reunion->id}",
            'Título: '.($reunion->titulo ?: 'Sin título'),
            'Fecha: '.$reunion->iniciada_en->translatedFormat('l j \d\e F \d\e Y, H:i'),
            'Duración: '.intdiv((int) $reunion->duracion_segundos, 60).' minutos',
            'Resumen: '.($reunion->resumen?->contenido['resumen_ejecutivo'] ?? 'Sin resumen.'),
        ]))->implode("\n\n");
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'desde' => $schema->string()->description('Fecha inicial en formato AAAA-MM-DD, o vacío.')->nullable()->required(),
            'hasta' => $schema->string()->description('Fecha final en formato AAAA-MM-DD, o vacío.')->nullable()->required(),
            'texto' => $schema->string()->description('Palabras a buscar en el título o el resumen, o vacío.')->nullable()->required(),
        ];
    }
}
