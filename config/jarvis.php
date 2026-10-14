<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Grabación
    |--------------------------------------------------------------------------
    |
    | El navegador graba la reunión en fragmentos independientes y sube cada
    | uno al terminar. Con 32 kbps un fragmento de 4 minutos pesa ~1 MB, por
    | debajo del upload_max_filesize de 2 MB que trae PHP por defecto.
    |
    */

    'fragmento_segundos' => (int) env('JARVIS_FRAGMENTO_SEGUNDOS', 240),

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
    | Resumen
    |--------------------------------------------------------------------------
    |
    | Modelo de texto que redacta el acta. GPT-6 Luna es el más económico; si
    | los resúmenes quedan cortos se puede subir a gpt-6.1-sol.
    |
    */

    'resumen' => [
        'modelo' => env('JARVIS_MODELO_RESUMEN', 'gpt-6-luna'),
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
