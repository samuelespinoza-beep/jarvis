<?php

namespace App\Ai\Tools;

use App\Support\Navegador;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\XPath;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Visita una página web y devuelve sus titulares con enlaces o su texto.
 */
class LeerPagina implements Tool
{
    private const MAX_ENLACES = 40;

    private const MAX_CARACTERES_TEXTO = 12000;

    public function __construct(private Navegador $navegador = new Navegador) {}

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Visita una página web pública. En modo "enlaces" devuelve los titulares o enlaces '
            .'principales con su URL (útil para portadas de diarios como https://www.infobae.com/). '
            .'En modo "texto" devuelve el contenido de un artículo para poder resumirlo.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $url = $request->string('url')->trim()->toString();
        $modo = $request->string('modo', 'enlaces')->toString();

        if (! preg_match('#^https?://#i', $url)) {
            $url = "https://{$url}";
        }

        try {
            $respuesta = $this->navegador->get($url);
        } catch (Throwable $e) {
            return "No se pudo abrir {$url}: {$e->getMessage()}";
        }

        if ($respuesta->failed()) {
            return "La página {$url} respondió con el código {$respuesta->status()}.";
        }

        if (! str_contains((string) $respuesta->header('Content-Type'), 'html')) {
            return "La dirección {$url} no es una página web (HTML).";
        }

        $urlFinal = (string) ($respuesta->effectiveUri() ?? $url);
        $documento = HTMLDocument::createFromString($respuesta->body(), LIBXML_NOERROR);

        $titulo = $this->limpiar($documento->querySelector('title')?->textContent ?? '');
        $descripcion = $documento->querySelector('meta[name="description"]')?->getAttribute('content');

        $encabezado = array_filter([
            "Página: {$titulo}",
            "URL: {$urlFinal}",
            $descripcion ? 'Descripción: '.$this->limpiar($descripcion) : null,
        ]);

        $cuerpo = $modo === 'texto'
            ? $this->texto($documento)
            : $this->enlaces($documento, $urlFinal);

        return implode("\n", $encabezado)."\n\n".$cuerpo;
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->description('Dirección completa de la página, por ejemplo https://www.infobae.com/')->required(),
            'modo' => $schema->string()->enum(['enlaces', 'texto'])->description('"enlaces" para titulares, "texto" para leer un artículo.')->required(),
        ];
    }

    private function enlaces(HTMLDocument $documento, string $base): string
    {
        $dominio = Str::after((string) parse_url($base, PHP_URL_HOST), 'www.');
        $vistos = [];
        $lineas = [];

        foreach ($documento->querySelectorAll('a[href]') as $enlace) {
            $texto = $this->textoDelEnlace($enlace);
            $destino = Navegador::resolver($base, (string) $enlace->getAttribute('href'));

            // Los titulares suelen ser textos largos; los menús y botones, cortos.
            if (! $destino || mb_strlen($texto) < 25 || isset($vistos[$destino])) {
                continue;
            }

            if (! str_ends_with((string) parse_url($destino, PHP_URL_HOST), $dominio)) {
                continue;
            }

            $vistos[$destino] = true;
            $lineas[] = "- {$texto} — {$destino}";

            if (count($lineas) >= self::MAX_ENLACES) {
                break;
            }
        }

        return $lineas === []
            ? 'No se encontraron titulares en la página. Puede que se cargue con JavaScript; prueba con la búsqueda web.'
            : "Enlaces principales (título — URL):\n".implode("\n", $lineas);
    }

    private function texto(HTMLDocument $documento): string
    {
        $contenedor = $documento->querySelector('article')
            ?? $documento->querySelector('main')
            ?? $documento->body;

        $parrafos = [];

        foreach ($contenedor?->querySelectorAll('h1, h2, h3, p, li') ?? [] as $nodo) {
            $texto = $this->limpiar($nodo->textContent);

            if (mb_strlen($texto) >= 3) {
                $parrafos[] = $texto;
            }
        }

        return $parrafos === []
            ? 'La página no tiene texto legible.'
            : Str::limit(implode("\n", $parrafos), self::MAX_CARACTERES_TEXTO);
    }

    /**
     * En las portadas un enlace suele envolver titular, bajada y autor; se
     * prefiere el titular y, si no hay, se separan los textos con espacios.
     */
    private function textoDelEnlace(Element $enlace): string
    {
        $titular = $enlace->querySelector('h1, h2, h3, h4');

        if ($titular) {
            return $this->limpiar($titular->textContent);
        }

        $textos = [];

        foreach ((new XPath($enlace->ownerDocument))->query('.//text()', $enlace) as $nodo) {
            $textos[] = $nodo->textContent;
        }

        return Str::limit($this->limpiar(implode(' ', $textos)), 200);
    }

    private function limpiar(string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }
}
