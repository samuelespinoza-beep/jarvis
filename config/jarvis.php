<?php

return [

    // Versión instalada (una persona en su PC): sin login, se entra con un usuario local.
    'escritorio' => (bool) env('JARVIS_ESCRITORIO', false),

    // El navegador sube la reunión en fragmentos de 1 minuto. A 32 kbps cada uno pesa
    // ~240 KB, por debajo del upload_max_filesize de 2 MB que trae PHP por defecto.
    'fragmento_segundos' => (int) env('JARVIS_FRAGMENTO_SEGUNDOS', 60),

    'audio_bits_por_segundo' => (int) env('JARVIS_AUDIO_BPS', 32000),

    // Tamaño máximo aceptado por fragmento, en kilobytes.
    'fragmento_max_kb' => (int) env('JARVIS_FRAGMENTO_MAX_KB', 20480),

    // El modelo "diarize" separa a los hablantes.
    'transcripcion' => [
        'modelo' => env('JARVIS_MODELO_TRANSCRIPCION', 'gpt-4o-transcribe-diarize'),
        'idioma' => env('JARVIS_IDIOMA', 'es'),
        'timeout_segundos' => 300,
    ],

    // Órdenes de voz: el micrófono va directo a OpenAI Realtime solo para detectar
    // órdenes; no se guarda (el acta sale de los fragmentos), así que se paga aparte.
    'en_vivo' => [
        'habilitada' => (bool) env('JARVIS_TRANSCRIPCION_EN_VIVO', true),
        'modelo' => env('JARVIS_MODELO_EN_VIVO', 'gpt-4o-mini-transcribe'),
        // Lo que dura la llave temporal para conectarse (la conexión sigue abierta después).
        'segundos_llave' => 120,
    ],

    'resumen' => [
        'modelo' => env('JARVIS_MODELO_RESUMEN', 'gpt-6-luna'),
        // Resumen corto de cada minuto mientras la reunión sigue (línea de tiempo).
        'por_tramos' => (bool) env('JARVIS_RESUMEN_POR_TRAMOS', true),
    ],

    // Los documentos e imágenes adjuntos los lee OpenAI directamente, sin extraer texto en el servidor.
    'chat' => [
        'modelo' => env('JARVIS_MODELO_CHAT', 'gpt-6-luna'),

        // Tamaño máximo del documento, en kilobytes. En la práctica también lo
        // limitan upload_max_filesize y post_max_size del php.ini.
        'documento_max_kb' => (int) env('JARVIS_DOCUMENTO_MAX_KB', 10240),

        'documento_formatos' => ['pdf', 'docx', 'txt', 'md', 'csv'],

        'imagen_formatos' => ['jpg', 'jpeg', 'png', 'webp'],
    ],

    // Botón "En vivo" del chat: conversación por voz con OpenAI Realtime. Se cobra por minuto de audio.
    'conversacion' => [
        'modelo' => env('JARVIS_MODELO_CONVERSACION', 'gpt-realtime-2.1-mini'),
        // Lo que dura la llave temporal para conectarse (la conversación sigue abierta después).
        'segundos_llave' => 120,
        // Sin hablar este tiempo, la conversación se cierra sola para no seguir cobrando.
        'minutos_inactividad' => 3,
        // Voces de la lista que no existen en tiempo real y su reemplazo más parecido.
        'voces_equivalentes' => ['onyx' => 'cedar', 'nova' => 'marin', 'fable' => 'ash'],
        // Filtro de ruido de OpenAI: far_field para el micrófono de la laptop, near_field para audífonos con micrófono.
        'microfono' => env('JARVIS_CONVERSACION_MICROFONO', 'far_field'),
        // Cuándo se considera que el usuario habló. Un umbral alto ignora golpes, eco y ruido de fondo.
        'deteccion' => [
            // server_vad usa el umbral de volumen; semantic_vad no tiene umbral y se dispara con cualquier sonido.
            'tipo' => env('JARVIS_CONVERSACION_DETECCION', 'server_vad'),
            // De 0 a 1: más alto exige voz más clara y fuerte para contar como turno.
            'umbral' => (float) env('JARVIS_CONVERSACION_UMBRAL', 0.7),
            // Voz mínima previa que se guarda y silencio que cierra el turno.
            'relleno_ms' => 300,
            'silencio_ms' => (int) env('JARVIS_CONVERSACION_SILENCIO_MS', 700),
            // Si es false, el micrófono se cierra mientras el asistente habla: con parlantes evita que
            // su propia voz vuelva a entrar como eco. Con audífonos se puede activar para interrumpirlo.
            'interrumpir' => (bool) env('JARVIS_CONVERSACION_INTERRUMPIR', false),
        ],
    ],

    // Botón de micrófono del chat: se transcribe en el servidor y el texto queda en la caja.
    'dictado' => [
        'modelo' => env('JARVIS_MODELO_DICTADO', 'gpt-4o-transcribe'),
        // Pasado este tiempo el navegador corta la grabación y la transcribe.
        'max_segundos' => 120,
        'max_kb' => 5120,
    ],

    // Lectura en voz alta de las respuestas del chat; solo se genera cuando se pide y se cobra por carácter.
    'voz' => [
        'modelo' => env('JARVIS_MODELO_VOZ', 'gpt-4o-mini-tts'),
        // Voz por defecto; cada usuario puede elegir otra en el chat.
        'voz' => env('JARVIS_VOZ', 'onyx'),
        'instrucciones' => 'Habla en español latinoamericano neutro, con tono profesional, tranquilo y claro. '
            .'Ritmo pausado y pronunciación clara de cifras y fechas.',

        // Todas probadas con gpt-4o-mini-tts.
        'voces' => [
            'onyx' => 'Masculina, grave',
            'ash' => 'Masculina, clara',
            'echo' => 'Masculina, suave',
            'ballad' => 'Masculina, expresiva',
            'cedar' => 'Masculina, natural',
            'verse' => 'Masculina, versátil',
            'coral' => 'Femenina, cálida',
            'nova' => 'Femenina, enérgica',
            'shimmer' => 'Femenina, suave',
            'sage' => 'Femenina, serena',
            'marin' => 'Femenina, natural',
            'alloy' => 'Neutra',
            'fable' => 'Narrador, acento británico',
        ],
        // OpenAI acepta hasta 4096 caracteres por pedido; lo que pase de aquí no se lee.
        'max_caracteres' => 4000,
    ],

];
