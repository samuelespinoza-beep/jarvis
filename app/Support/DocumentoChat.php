<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Límites de los documentos que se adjuntan al chat. El tamaño máximo real es
 * el menor entre lo configurado y lo que acepta el php.ini, para que el
 * navegador avise antes de subir un archivo que PHP descartaría.
 */
class DocumentoChat
{
    /**
     * @return list<string>
     */
    public static function formatos(): array
    {
        return config('jarvis.chat.documento_formatos');
    }

    public static function maxKb(): int
    {
        $php = intdiv(UploadedFile::getMaxFilesize(), 1024);

        return min((int) config('jarvis.chat.documento_max_kb'), $php ?: PHP_INT_MAX);
    }
}
