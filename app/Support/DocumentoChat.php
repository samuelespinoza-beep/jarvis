<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;

/**
 * Límites de los archivos que se adjuntan al chat (documentos e imágenes). El
 * tamaño máximo real es el menor entre lo configurado y lo que acepta el
 * php.ini, para que el navegador avise antes de subir un archivo que PHP
 * descartaría.
 */
class DocumentoChat
{
    /**
     * Cómo guarda el SDK los adjuntos en el historial de la conversación.
     */
    public const TIPOS = ['stored-document', 'stored-image'];

    /**
     * @return list<string>
     */
    public static function formatos(): array
    {
        return [...config('jarvis.chat.documento_formatos'), ...self::imagenes()];
    }

    /**
     * @return list<string>
     */
    public static function imagenes(): array
    {
        return config('jarvis.chat.imagen_formatos');
    }

    public static function esImagen(string $archivo): bool
    {
        return in_array(strtolower(pathinfo($archivo, PATHINFO_EXTENSION)), self::imagenes(), true);
    }

    public static function maxKb(): int
    {
        $php = intdiv(UploadedFile::getMaxFilesize(), 1024);

        return min((int) config('jarvis.chat.documento_max_kb'), $php ?: PHP_INT_MAX);
    }

    /**
     * @return list<string>
     */
    public static function reglas(): array
    {
        $formatos = self::formatos();

        return [
            'file',
            'extensions:'.implode(',', $formatos),
            // Un .md se detecta como texto plano (txt).
            'mimes:'.implode(',', array_diff($formatos, ['md'])),
            'max:'.self::maxKb(),
        ];
    }

    /**
     * Mensajes de error de las reglas de un adjunto, para el campo indicado
     * ("documento" o "archivos.*", por ejemplo).
     *
     * @return array<string, string>
     */
    public static function mensajes(string $campo): array
    {
        $nombres = array_map(fn (string $formato) => strtoupper($formato), self::formatos());
        $lista = implode(', ', array_slice($nombres, 0, -1)).' o '.end($nombres);

        return [
            "{$campo}.uploaded" => 'No se pudo subir el archivo. Puede que supere el tamaño que permite el servidor.',
            "{$campo}.extensions" => "Formato no admitido. Usa {$lista}.",
            "{$campo}.mimes" => 'El contenido del archivo no coincide con su formato.',
            "{$campo}.max" => 'El archivo supera el máximo de '.round(self::maxKb() / 1024, 1).' MB.',
        ];
    }

    /**
     * Un archivo recién subido, sin guardarlo, listo para mandárselo al modelo.
     */
    public static function desdeSubida(UploadedFile $archivo): File
    {
        $nombre = $archivo->getClientOriginalName();
        $adjunto = self::esImagen($nombre) ? Image::fromUpload($archivo) : Document::fromUpload($archivo);

        return $adjunto->as($nombre);
    }

    /**
     * El archivo guardado listo para mandárselo al modelo: como imagen o como documento.
     */
    public static function adjunto(string $ruta, string $nombre, ?string $disco = null): File
    {
        $archivo = self::esImagen($ruta) ? Image::fromStorage($ruta, $disco) : Document::fromStorage($ruta, $disco);

        return $archivo->as($nombre);
    }

    /**
     * El primer adjunto (documento o imagen) de un mensaje guardado.
     *
     * @param  array<int, array<string, mixed>>|null  $adjuntos
     * @return array{nombre: string, imagen: bool}|null
     */
    public static function delMensaje(?array $adjuntos): ?array
    {
        $adjunto = collect($adjuntos)->first(fn (array $adjunto) => in_array($adjunto['type'] ?? null, self::TIPOS, true));

        if (! $adjunto) {
            return null;
        }

        return [
            'nombre' => $adjunto['name'] ?? basename($adjunto['path'] ?? ''),
            'imagen' => $adjunto['type'] === 'stored-image',
        ];
    }

    /**
     * Cómo contar en texto plano lo que adjuntó el usuario.
     *
     * @param  array{nombre: string, imagen: bool}  $adjunto
     */
    public static function describir(array $adjunto): string
    {
        return ($adjunto['imagen'] ? 'la imagen' : 'el documento')." «{$adjunto['nombre']}»";
    }
}
