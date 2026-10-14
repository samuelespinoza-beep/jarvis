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
    | Transcripción en vivo
    |--------------------------------------------------------------------------
    |
    | Mientras se graba, el navegador envía el audio directamente a OpenAI
    | Realtime (WebRTC) y muestra el texto a medida que se habla. Es solo para
    | ver en pantalla: no separa hablantes ni se guarda. El acta se sigue
    | haciendo con la transcripción por fragmentos, así que este audio se
    | paga dos veces.
    |
    */

    'en_vivo' => [
        'habilitada' => (bool) env('JARVIS_TRANSCRIPCION_EN_VIVO', true),
        'modelo' => env('JARVIS_MODELO_EN_VIVO', 'gpt-4o-transcribe'),
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
    | ver videos de YouTube y consultar las reuniones guardadas.
    |
    */

    'chat' => [
        'modelo' => env('JARVIS_MODELO_CHAT', 'gpt-6-luna'),
    ],

];
