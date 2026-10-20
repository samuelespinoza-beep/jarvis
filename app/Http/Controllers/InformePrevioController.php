<?php

namespace App\Http\Controllers;

use App\Ai\Agents\PreparadorInforme;
use App\Enums\EstadoReunion;
use App\Models\Reunion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Informe previo a una reunión: compromisos pendientes, temas por retomar,
 * alertas y sugerencias de agenda, a partir de las actas de las últimas
 * reuniones. Se genera a pedido, antes de empezar o durante la reunión.
 */
class InformePrevioController extends Controller
{
    // Actas que se revisan, de la más reciente hacia atrás.
    private const REUNIONES = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'agenda' => ['nullable', 'array', 'max:20'],
            'agenda.*' => ['nullable', 'string', 'max:200'],
        ]);

        $agenda = array_values(array_filter(array_map(fn ($punto) => trim((string) $punto), $datos['agenda'] ?? [])));

        $reuniones = Reunion::query()
            ->where('estado', EstadoReunion::Completada)
            ->whereHas('resumen')
            ->with('resumen')
            ->latest('iniciada_en')
            ->limit(self::REUNIONES)
            ->get();

        $revisadas = $reuniones->map(fn (Reunion $reunion) => [
            'id' => $reunion->id,
            'titulo' => $reunion->titulo ?: 'Sin título',
            'fecha' => $reunion->iniciada_en->toIso8601String(),
        ])->all();

        if ($reuniones->isEmpty()) {
            return response()->json(['reuniones' => [], 'informe' => null]);
        }

        try {
            $informe = (new PreparadorInforme)
                ->prompt($this->prompt($reuniones->all(), $agenda), model: config('jarvis.resumen.modelo'))
                ->toArray();
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se pudo preparar el informe previo. Intenta de nuevo.'], 502);
        }

        // Vencido se decide aquí, con la fecha de hoy, no lo decide el modelo.
        $hoy = now()->startOfDay();
        $informe['compromisos'] = array_map(function (array $compromiso) use ($hoy) {
            $fecha = $this->fecha($compromiso['fecha_iso'] ?? null);

            return [...$compromiso, 'vencido' => $fecha !== null && $fecha->lt($hoy)];
        }, $informe['compromisos'] ?? []);

        return response()->json([
            'generado_en' => now()->toIso8601String(),
            'reuniones' => $revisadas,
            'informe' => $informe,
        ]);
    }

    /**
     * @param  list<Reunion>  $reuniones
     * @param  list<string>  $agenda
     */
    private function prompt(array $reuniones, array $agenda): string
    {
        $bloques = array_map(function (Reunion $reunion) {
            $acta = $reunion->resumen->contenido;
            $acuerdos = array_map(
                fn (array $acuerdo) => '- '.$acuerdo['descripcion']
                    .' (responsable: '.($acuerdo['responsable'] ?? 'no consta')
                    .'; fecha límite: '.($acuerdo['fecha_limite'] ?? 'no consta').')',
                $acta['acuerdos'] ?? [],
            );

            return implode("\n", [
                '### '.($reunion->titulo ?: 'Sin título').' — '.$reunion->iniciada_en->translatedFormat('l j \d\e F \d\e Y'),
                'Resumen: '.($acta['resumen_ejecutivo'] ?? ''),
                'Acuerdos:',
                ...($acuerdos ?: ['- Ninguno.']),
                'Pendientes: '.(implode('; ', $acta['pendientes'] ?? []) ?: 'ninguno'),
                'Riesgos: '.(implode('; ', $acta['riesgos_o_puntos_criticos'] ?? []) ?: 'ninguno'),
            ]);
        }, $reuniones);

        $agendaTexto = $agenda
            ? "Agenda de la próxima reunión:\n".implode("\n", array_map(fn ($punto, $i) => ($i + 1).'. '.$punto, $agenda, array_keys($agenda)))
            : 'La próxima reunión todavía no tiene agenda.';

        return $agendaTexto."\n\nActas de las últimas reuniones:\n\n".implode("\n\n", $bloques);
    }

    private function fecha(?string $iso): ?Carbon
    {
        if (! $iso || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $iso)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
