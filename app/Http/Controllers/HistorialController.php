<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReunionResource;
use App\Models\Resumen;
use App\Models\Reunion;
use App\Models\SegmentoTranscripcion;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HistorialController extends Controller
{
    public function index(): Response
    {
        $reuniones = Reunion::query()
            ->withCount('fragmentos')
            ->withSum('fragmentos', 'duracion_segundos')
            ->with('resumen')
            ->latest('iniciada_en')
            ->paginate(20)
            ->through(fn (Reunion $reunion) => [
                ...(new ReunionResource($reunion))->resolve(),
                'resumen_ejecutivo' => $reunion->resumen?->contenido['resumen_ejecutivo'] ?? null,
            ]);

        return Inertia::render('reuniones/Index', [
            'reuniones' => $reuniones,
        ]);
    }

    public function show(Request $request, Reunion $reunion): Response
    {
        $reunion->loadCount('fragmentos');

        $versiones = $reunion->resumenes()->get(['id', 'version', 'modelo', 'created_at']);

        /** @var Resumen|null $resumen */
        $resumen = $request->filled('version')
            ? $reunion->resumenes()->where('version', $request->integer('version'))->first()
            : $reunion->resumen;

        return Inertia::render('reuniones/Show', [
            'reunion' => [
                ...(new ReunionResource($reunion))->resolve(),
                'costo_audio_segundos' => $reunion->costo_audio_segundos,
                'costo_tokens' => $reunion->costo_tokens,
            ],
            'resumen' => $resumen ? [
                'version' => $resumen->version,
                'modelo' => $resumen->modelo,
                'generado_en' => $resumen->created_at?->toIso8601String(),
                'contenido' => $resumen->contenido,
            ] : null,
            'versiones' => $versiones->map(fn (Resumen $version) => [
                'version' => $version->version,
                'modelo' => $version->modelo,
                'generado_en' => $version->created_at?->toIso8601String(),
            ]),
            'segmentos' => $reunion->segmentos()->get()->map(fn (SegmentoTranscripcion $segmento) => [
                'hablante' => $segmento->hablante,
                'inicio_segundos' => $segmento->inicio_segundos,
                'texto' => $segmento->texto,
            ]),
        ]);
    }
}
