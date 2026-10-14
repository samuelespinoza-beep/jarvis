<?php

namespace App\Support;

use Closure;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

/**
 * Cliente HTTP para que Jarvis visite páginas públicas. Como la URL la elige
 * la IA, se bloquea cualquier dirección local o de la red interna (también en
 * las redirecciones) para que nadie pueda usar a Jarvis para ver equipos de
 * la oficina.
 */
class Navegador
{
    /**
     * Resolución DNS reemplazable en los tests, para no depender de internet.
     *
     * @var (Closure(string): list<string>)|null
     */
    public static ?Closure $resolverDns = null;

    private const AGENTE = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36';

    /**
     * @param  array<string, string>  $cabeceras
     *
     * @throws InvalidArgumentException si la URL no es pública.
     */
    public function get(string $url, array $cabeceras = []): Response
    {
        $this->validar(new Uri($url));

        return Http::timeout(20)
            ->withHeaders([
                'User-Agent' => self::AGENTE,
                'Accept-Language' => 'es-PE,es;q=0.9',
                ...$cabeceras,
            ])
            ->withOptions([
                'allow_redirects' => [
                    'max' => 5,
                    'on_redirect' => fn ($peticion, $respuesta, UriInterface $destino) => $this->validar($destino),
                ],
            ])
            ->get($url);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function validar(UriInterface $uri): void
    {
        if (! in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getHost() === '') {
            throw new InvalidArgumentException('Solo se pueden visitar direcciones http o https.');
        }

        $resolver = static::$resolverDns ?? fn (string $host) => gethostbynamel($host) ?: [];
        $ips = $resolver($uri->getHost());

        if ($ips === []) {
            throw new InvalidArgumentException("No se encontró el sitio {$uri->getHost()}.");
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException('No se permite visitar direcciones locales o de la red interna.');
            }
        }
    }

    /**
     * Convierte un enlace relativo en absoluto respecto de la página.
     */
    public static function resolver(string $base, string $enlace): ?string
    {
        $enlace = trim($enlace);

        if ($enlace === '' || str_starts_with($enlace, '#') || preg_match('/^(mailto|tel|javascript):/i', $enlace)) {
            return null;
        }

        $resuelto = (string) UriResolver::resolve(new Uri($base), new Uri($enlace));

        return preg_match('#^https?://#i', $resuelto) ? $resuelto : null;
    }
}
