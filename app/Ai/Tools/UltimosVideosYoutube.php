<?php

namespace App\Ai\Tools;

use App\Support\Navegador;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * Lista los últimos videos de un canal de YouTube.
 *
 * Sin credenciales lee la pestaña "Videos" del canal, igual que un navegador.
 * Si hay YOUTUBE_API_KEY en el .env usa la YouTube Data API v3 (fechas exactas
 * y un formato estable) y, si la API falla, vuelve a leer la página.
 */
class UltimosVideosYoutube implements Tool
{
    public function __construct(private Navegador $navegador = new Navegador) {}

    public function description(): Stringable|string
    {
        return 'Devuelve los últimos videos publicados por un canal de YouTube (título, fecha y enlace). '
            .'Acepta el @usuario del canal (por ejemplo @infobae), la URL del canal o su ID (empieza con UC). '
            .'Si solo conoces el nombre del canal, busca primero su @usuario en internet.';
    }

    public function handle(Request $request): Stringable|string
    {
        $canal = $request->string('canal')->trim()->toString();
        $cantidad = max(1, min(15, $request->integer('cantidad', 10)));

        try {
            if (filled(config('services.youtube.api_key'))) {
                try {
                    return $this->desdeApi($canal, $cantidad);
                } catch (Throwable) {
                    // Cuota agotada o llave inválida: se intenta leyendo la página.
                }
            }

            return $this->desdePagina($canal, $cantidad);
        } catch (Throwable $e) {
            return "No se pudo consultar YouTube: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'canal' => $schema->string()->description('@usuario, URL o ID del canal de YouTube.')->required(),
            'cantidad' => $schema->integer()->min(1)->max(15)->description('Cuántos videos devolver (máximo 15).')->required(),
        ];
    }

    private function desdePagina(string $canal, int $cantidad): string
    {
        // La cookie evita la página de consentimiento de cookies de Google.
        $respuesta = $this->navegador->get($this->urlVideos($canal), ['Cookie' => 'CONSENT=YES+1']);

        if ($respuesta->status() === 404) {
            return "No se encontró el canal de YouTube \"{$canal}\". Verifica su @usuario.";
        }

        if ($respuesta->failed()) {
            throw new RuntimeException("YouTube respondió con el código {$respuesta->status()}.");
        }

        if (! preg_match('/var ytInitialData = (\{.*?\});<\/script>/s', $respuesta->body(), $coincidencia)) {
            throw new RuntimeException('YouTube cambió el formato de su página y no se pudo leer la lista de videos.');
        }

        $datos = json_decode($coincidencia[1], true) ?? [];
        $nombre = $datos['metadata']['channelMetadataRenderer']['title'] ?? $canal;
        $idCanal = $datos['metadata']['channelMetadataRenderer']['externalId'] ?? null;

        $videos = [];
        $this->recolectarVideos($datos, $videos);
        $videos = array_slice($videos, 0, $cantidad);

        if ($videos === []) {
            return "El canal {$nombre} no tiene videos publicados (o YouTube no los mostró).";
        }

        $lineas = ["Canal: {$nombre}"];

        if ($idCanal) {
            $lineas[] = "URL del canal: https://www.youtube.com/channel/{$idCanal}";
        }

        $lineas[] = '';

        foreach ($videos as $video) {
            $cuando = $video['publicado'] ? " ({$video['publicado']})" : '';
            $lineas[] = "- {$video['titulo']}{$cuando} — https://www.youtube.com/watch?v={$video['id']}";
        }

        return implode("\n", $lineas);
    }

    /**
     * Recorre los datos de la página y junta los videos en el orden en que
     * aparecen (del más reciente al más antiguo). Soporta el formato actual
     * (lockupViewModel) y el anterior (videoRenderer).
     *
     * @param  array<array-key, mixed>  $nodo
     * @param  array<string, array{id: string, titulo: string, publicado: ?string}>  $videos
     */
    private function recolectarVideos(array $nodo, array &$videos): void
    {
        if (isset($nodo['lockupViewModel']['contentId'])) {
            $vista = $nodo['lockupViewModel'];
            $metadatos = $vista['metadata']['lockupMetadataViewModel'] ?? [];
            $partes = [];

            foreach ($metadatos['metadata']['contentMetadataViewModel']['metadataRows'] ?? [] as $fila) {
                foreach ($fila['metadataParts'] ?? [] as $parte) {
                    $partes[] = $parte['text']['content'] ?? null;
                }
            }

            $this->agregar($videos, $vista['contentId'], $metadatos['title']['content'] ?? null, end($partes) ?: null);

            return;
        }

        if (isset($nodo['videoRenderer']['videoId'])) {
            $video = $nodo['videoRenderer'];
            $this->agregar(
                $videos,
                $video['videoId'],
                $video['title']['runs'][0]['text'] ?? null,
                $video['publishedTimeText']['simpleText'] ?? null,
            );

            return;
        }

        foreach ($nodo as $hijo) {
            if (is_array($hijo)) {
                $this->recolectarVideos($hijo, $videos);
            }
        }
    }

    /**
     * @param  array<string, array{id: string, titulo: string, publicado: ?string}>  $videos
     */
    private function agregar(array &$videos, string $id, ?string $titulo, ?string $publicado): void
    {
        if ($titulo && ! isset($videos[$id])) {
            $videos[$id] = ['id' => $id, 'titulo' => $titulo, 'publicado' => $publicado];
        }
    }

    private function urlVideos(string $canal): string
    {
        if (preg_match('/^(UC[\w-]{22})$/', $canal, $coincidencia)) {
            return "https://www.youtube.com/channel/{$coincidencia[1]}/videos";
        }

        if (preg_match('#^https?://(www\.)?youtube\.com/(@[\w.-]+|channel/UC[\w-]{22})#', $canal, $coincidencia)) {
            return "https://www.youtube.com/{$coincidencia[2]}/videos";
        }

        return 'https://www.youtube.com/@'.str_replace(' ', '', ltrim($canal, '@')).'/videos';
    }

    private function desdeApi(string $canal, int $cantidad): string
    {
        $datosCanal = $this->api()->get('channels', [
            'part' => 'snippet,contentDetails',
            ...$this->filtroApi($canal),
        ])->throw()->json('items.0');

        if (! $datosCanal) {
            return "No se encontró el canal de YouTube \"{$canal}\". Verifica su @usuario.";
        }

        $videos = $this->api()->get('playlistItems', [
            'part' => 'snippet',
            'playlistId' => $datosCanal['contentDetails']['relatedPlaylists']['uploads'],
            'maxResults' => $cantidad,
        ])->throw()->json('items', []);

        if ($videos === []) {
            return "El canal {$datosCanal['snippet']['title']} no tiene videos publicados.";
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

        return implode("\n", $lineas);
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
    private function filtroApi(string $canal): array
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
