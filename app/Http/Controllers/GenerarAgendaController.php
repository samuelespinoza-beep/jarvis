<?php

namespace App\Http\Controllers;

use App\Ai\Agents\GeneradorAgenda;
use App\Support\DocumentoChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Propone la agenda de una reunión a partir de capturas, documentos o un
 * texto pegado. No guarda nada: el usuario revisa los puntos y decide si
 * reemplazan la agenda o se agregan a ella. Los archivos no se guardan.
 */
class GenerarAgendaController extends Controller
{
    private const MAX_ARCHIVOS = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'texto' => ['nullable', 'string', 'max:20000'],
            'archivos' => ['nullable', 'array', 'max:'.self::MAX_ARCHIVOS],
            'archivos.*' => DocumentoChat::reglas(),
        ], [
            ...DocumentoChat::mensajes('archivos.*'),
            'archivos.max' => 'Puedes adjuntar hasta '.self::MAX_ARCHIVOS.' archivos.',
        ]);

        $texto = trim($datos['texto'] ?? '');
        /** @var list<UploadedFile> $archivos */
        $archivos = $request->file('archivos', []);

        if ($texto === '' && ! $archivos) {
            throw ValidationException::withMessages([
                'archivos' => 'Adjunta una imagen o un documento con la agenda, o pega su texto.',
            ]);
        }

        $prompt = $texto !== ''
            ? 'Arma la agenda con este texto'.($archivos ? ' y los archivos adjuntos' : '').":\n\n{$texto}"
            : 'Arma la agenda con los archivos adjuntos.';

        try {
            $respuesta = (new GeneradorAgenda)->prompt(
                $prompt,
                attachments: array_map(fn (UploadedFile $archivo) => DocumentoChat::desdeSubida($archivo), $archivos),
                model: config('jarvis.chat.modelo'),
            )->toArray();
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'No se pudo generar la agenda. Intenta de nuevo.'], 502);
        }

        // El modelo puede pasarse de largo: la agenda acepta puntos de hasta 200 caracteres.
        $puntos = collect($respuesta['puntos'] ?? [])
            ->map(fn ($punto) => Str::limit(trim((string) $punto), GeneradorAgenda::MAX_CARACTERES - 1, '…'))
            ->filter()
            ->unique()
            ->take(GeneradorAgenda::MAX_PUNTOS)
            ->values()
            ->all();

        return response()->json([
            'puntos' => $puntos,
            'aviso' => $respuesta['aviso'] ?? null,
        ]);
    }
}
