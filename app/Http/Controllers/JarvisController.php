<?php

namespace App\Http\Controllers;

use App\Enums\EstadoReunion;
use App\Http\Resources\ReunionResource;
use App\Models\Reunion;
use App\Support\DocumentoChat;
use Inertia\Inertia;
use Inertia\Response;

class JarvisController extends Controller
{
    public function __invoke(): Response
    {
        $reunionActiva = Reunion::query()
            ->whereIn('estado', EstadoReunion::abiertos())
            ->withCount('fragmentos')
            ->withSum('fragmentos', 'duracion_segundos')
            ->latest('iniciada_en')
            ->first();

        $ultimaReunion = Reunion::query()
            ->whereNotIn('estado', EstadoReunion::abiertos())
            ->withCount('fragmentos')
            ->latest('iniciada_en')
            ->first();

        return Inertia::render('Jarvis', [
            'reunionActiva' => $reunionActiva ? (new ReunionResource($reunionActiva))->resolve() : null,
            'ultimaReunion' => $ultimaReunion ? (new ReunionResource($ultimaReunion))->resolve() : null,
            'grabacion' => [
                'fragmentoSegundos' => config('jarvis.fragmento_segundos'),
                'audioBitsPorSegundo' => config('jarvis.audio_bits_por_segundo'),
                'enVivo' => config('jarvis.en_vivo.habilitada'),
            ],
            'documentos' => [
                'formatos' => DocumentoChat::formatos(),
                'maxKb' => DocumentoChat::maxKb(),
            ],
            'dictadoMaxSegundos' => config('jarvis.dictado.max_segundos'),
            'voces' => [
                'porDefecto' => config('jarvis.voz.voz'),
                'lista' => collect(config('jarvis.voz.voces'))
                    ->map(fn (string $descripcion, string $id) => ['id' => $id, 'descripcion' => $descripcion])
                    ->values(),
            ],
        ]);
    }
}
