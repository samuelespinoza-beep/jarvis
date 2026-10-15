<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Grabación
    |--------------------------------------------------------------------------
    |
    | El navegador graba la reunión en fragmentos independientes de 1 minuto y
    | sube cada uno al terminar. Cada minuto se transcribe y se resume por su
    | cuenta (línea de tiempo). Con 32 kbps un minuto pesa ~240 KB, muy por
    | debajo del upload_max_filesize de 2 MB que trae PHP por defecto.
    |
    */

    'fragmento_segundos' => (int) env('JARVIS_FRAGMENTO_SEGUNDOS', 60),

    'audio_bits_por_segundo' => (int) env('JARVIS_AUDIO_BPS', 32000),

    // Tamaño máximo aceptado por fragmento, en kilobytes.
    'fragmento_max_kb' => (int) env('JARVIS_FRAGMENTO_MAX_KB', 20480),

    /*
    |--------------------------------------------------------------------------
    | Transcripción
    |--------------------------------------------------------------------------
    |
    | Cada fragmento se transcribe con OpenAI en un job de la cola, apenas
    | llega al servidor. El modelo "diarize" separa a los hablantes.
    |
    */

    'transcripcion' => [
        'modelo' => env('JARVIS_MODELO_TRANSCRIPCION', 'gpt-4o-transcribe-diarize'),
        'idioma' => env('JARVIS_IDIOMA', 'es'),
        'timeout_segundos' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Órdenes de voz (transcripción en vivo)
    |--------------------------------------------------------------------------
    |
    | Mientras la reunión está abierta (grabando o en pausa), el navegador
    | envía el micrófono directamente a OpenAI Realtime (WebRTC) y recibe el
    | texto frase a frase para detectar órdenes como "Jarvis, pausa la
    | reunión". El texto no se muestra ni se guarda: el acta se sigue haciendo
    | con la transcripción por fragmentos, así que este audio se paga aparte.
    |
    */

    'en_vivo' => [
        'habilitada' => (bool) env('JARVIS_TRANSCRIPCION_EN_VIVO', true),
        'modelo' => env('JARVIS_MODELO_EN_VIVO', 'gpt-4o-mini-transcribe'),
        // Lo que dura la llave temporal para conectarse (la conexión sigue abierta después).
        'segundos_llave' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Resumen
    |--------------------------------------------------------------------------
    |
    | Modelo de texto que redacta el acta. GPT-6 Luna es el más económico; si
    | los resúmenes quedan cortos se puede subir a gpt-6.1-sol.
    |
    */

    'resumen' => [
        'modelo' => env('JARVIS_MODELO_RESUMEN', 'gpt-6-luna'),
        // Resumen corto de cada minuto mientras la reunión sigue (línea de tiempo).
        'por_tramos' => (bool) env('JARVIS_RESUMEN_POR_TRAMOS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat
    |--------------------------------------------------------------------------
    |
    | Modelo del chat con Jarvis, que puede buscar en internet, leer páginas,
    | ver videos de YouTube y consultar las reuniones guardadas. También se le
    | puede adjuntar un documento para resumirlo o hacerle preguntas: OpenAI
    | lee el archivo directamente, sin extraer el texto en el servidor.
    |
    */

    'chat' => [
        'modelo' => env('JARVIS_MODELO_CHAT', 'gpt-6-luna'),

        // Tamaño máximo del documento, en kilobytes. En la práctica también lo
        // limitan upload_max_filesize y post_max_size del php.ini.
        'documento_max_kb' => (int) env('JARVIS_DOCUMENTO_MAX_KB', 10240),

        'documento_formatos' => ['pdf', 'docx', 'txt', 'md', 'csv'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dictado
    |--------------------------------------------------------------------------
    |
    | Botón de micrófono del chat: el navegador graba lo que dice el usuario,
    | el servidor lo transcribe y el texto queda en la caja para enviarlo.
    |
    */

    'dictado' => [
        'modelo' => env('JARVIS_MODELO_DICTADO', 'gpt-4o-transcribe'),
        // Pasado este tiempo el navegador corta la grabación y la transcribe.
        'max_segundos' => 120,
        'max_kb' => 5120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Voz
    |--------------------------------------------------------------------------
    |
    | Jarvis puede leer en voz alta sus respuestas del chat (texto a voz de
    | OpenAI). Solo se genera audio cuando se pide, y se cobra por carácter.
    |
    */

    'voz' => [
        'modelo' => env('JARVIS_MODELO_VOZ', 'gpt-4o-mini-tts'),
        'voz' => env('JARVIS_VOZ', 'onyx'),
        'instrucciones' => 'Habla en español latinoamericano neutro, con voz grave, tranquila y sobria, '
            .'como un mayordomo profesional. Ritmo pausado y pronunciación clara de cifras y fechas.',
        // OpenAI acepta hasta 4096 caracteres por pedido; lo que pase de aquí no se lee.
        'max_caracteres' => 4000,
    ],

];
