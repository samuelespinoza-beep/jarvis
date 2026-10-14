<?php

namespace App\Http\Controllers;

use App\Enums\EstadoReunion;
use App\Http\Resources\ReunionResource;
use App\Models\Reunion;
use Inertia\Inertia;
use Inertia\Response;

class JarvisController extends Controller
{
    public function __invoke(): Response
    {
        $reunionActiva = Reunion::query()
            ->where('estado', EstadoReunion::Grabando)
            ->withCount('fragmentos')
            ->latest('iniciada_en')
            ->first();

        $ultimaReunion = Reunion::query()
            ->where('estado', '!=', EstadoReunion::Grabando)
            ->withCount('fragmentos')
            ->latest('iniciada_en')
            ->first();

        return Inertia::render('Jarvis', [
            'reunionActiva' => $reunionActiva ? (new ReunionResource($reunionActiva))->resolve() : null,
            'ultimaReunion' => $ultimaReunion ? (new ReunionResource($ultimaReunion))->resolve() : null,
            'grabacion' => [
                'fragmentoSegundos' => config('jarvis.fragmento_segundos'),
                'audioBitsPorSegundo' => config('jarvis.audio_bits_por_segundo'),
            ],
        ]);
    }
}
