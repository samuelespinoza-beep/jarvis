<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Lista los últimos videos de un canal con la YouTube Data API v3 (la misma
 * que usa Cronos). Cada consulta gasta 2 unidades de la cuota diaria gratuita.
 */
class UltimosVideosYoutube implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Devuelve los últimos videos publicados por un canal de YouTube (título, fecha y enlace). '
            .'Acepta el @usuario del canal (por ejemplo @infobae), la URL del canal o su ID (empieza con UC). '
            .'Si solo conoces el nombre del canal, busca primero su @usuario en internet.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        if (blank(config('services.youtube.api_key'))) {
            return 'La consulta de YouTube no está configurada (falta YOUTUBE_API_KEY en el archivo .env).';
        }

        $canal = $request->string('canal')->trim()->toString();
        $cantidad = max(1, min(15, $request->integer('cantidad', 10)));

        try {
            $datosCanal = $this->api()->get('channels', [
                'part' => 'snippet,contentDetails',
                ...$this->filtroCanal($canal),
            ])->throw()->json('items.0');

            if (! $datosCanal) {
                return "No se encontró el canal de YouTube \"{$canal}\". Verifica su @usuario.";
            }

            $videos = $this->api()->get('playlistItems', [
                'part' => 'snippet',
                'playlistId' => $datosCanal['contentDetails']['relatedPlaylists']['uploads'],
                'maxResults' => $cantidad,
            ])->throw()->json('items', []);
        } catch (Throwable $e) {
            return "No se pudo consultar YouTube: {$e->getMessage()}";
        }

        $lineas = [
            "Canal: {$datosCanal['snippet']['title']}",
            "URL del canal: https://www.youtube.com/channel/{$datosCanal['id']}",
            '',
        ];

        foreach ($videos as $video) {
            $snippet = $video['snippet'];
            $fecha = Carbon::parse($snippet['publishedAt'])->timezone(config('app.timezone'));
            $lineas[] = "- {$snippet['title']} ({$fecha->format('d/m/Y H:i')}) — https://www.youtube.com/watch?v={$snippet['resourceId']['videoId']}";
        }

        return $videos === []
            ? "El canal {$datosCanal['snippet']['title']} no tiene videos publicados."
            : implode("\n", $lineas);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'canal' => $schema->string()->description('@usuario, URL o ID del canal de YouTube.')->required(),
            'cantidad' => $schema->integer()->min(1)->max(15)->description('Cuántos videos devolver (máximo 15).')->required(),
        ];
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl(config('services.youtube.base_url'))
            ->timeout(15)
            ->withQueryParameters(['key' => config('services.youtube.api_key')]);
    }

    /**
     * @return array<string, string>
     */
    private function filtroCanal(string $canal): array
    {
        if (preg_match('/(UC[\w-]{22})/', $canal, $coincidencia)) {
            return ['id' => $coincidencia[1]];
        }

        // Acepta "@infobae", "infobae" o "https://www.youtube.com/@infobae".
        $usuario = preg_match('#youtube\.com/@([\w.-]+)#', $canal, $coincidencia)
            ? $coincidencia[1]
            : str_replace(' ', '', ltrim($canal, '@'));

        return ['forHandle' => $usuario];
    }
}
