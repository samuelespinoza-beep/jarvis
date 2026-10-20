import { http } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import type { Ref } from 'vue';
import {
    enviar as enviarMensaje,
    mensajes as cargarMensajes,
} from '@/actions/App/Http/Controllers/ChatController';
import { tokenXsrf } from '@/lib/xsrf';

export type MensajeChat = {
    rol: 'usuario' | 'jarvis';
    contenido: string;
    // Nombre del documento adjunto al mensaje del usuario.
    documento?: string | null;
    // Hora del mensaje (ISO), para intercalarlo con los resúmenes de la reunión.
    creado_en?: string | null;
    actividad?: string;
};

// Lo que se envía si se adjunta un documento sin escribir nada: Jarvis dice qué
// documento es y pregunta qué hacer con él, sin resumirlo por su cuenta.
export const MENSAJE_SOLO_DOCUMENTO = 'Te adjunto este documento.';

// Textos que se envían solos con un documento (el de antes pedía un resumen); no se muestran.
const TEXTOS_SOLO_DOCUMENTO = [
    MENSAJE_SOLO_DOCUMENTO,
    'Resume este documento y dime sus temas centrales.',
];

export function esSoloDocumento(mensaje: MensajeChat): boolean {
    return (
        !!mensaje.documento &&
        TEXTOS_SOLO_DOCUMENTO.includes(mensaje.contenido.trim())
    );
}

type EventoStream = {
    type: string;
    delta?: string;
    tool_name?: string;
    message?: string;
};

// Qué mostrar mientras el asistente usa cada herramienta (también en la conversación en vivo).
export const ACTIVIDADES: Record<string, string> = {
    BuscarEnInternet: 'Buscando en internet…',
    LeerPagina: 'Leyendo la página…',
    UltimosVideosYoutube: 'Revisando YouTube…',
    ConsultarReuniones: 'Buscando en las reuniones…',
    LeerReunion: 'Leyendo el acta…',
    PreguntarAlChat: 'Revisando el documento y el chat…',
};

/**
 * Chat con Jarvis. La respuesta llega como eventos SSE y se va escribiendo
 * a medida que el modelo la genera.
 *
 * Con una reunión abierta, el chat es el de esa reunión: al empezar otra o
 * terminarla, el chat se reinicia.
 */
export function useChat(reunionId: Ref<string | null>) {
    const mensajes = ref<MensajeChat[]>([]);
    const conversacionId = ref<string | null>(null);
    const enviando = ref(false);
    const error = ref<string | null>(null);

    /**
     * Trae los mensajes guardados: los de la reunión del chat o, en el chat
     * general, los de la conversación indicada.
     */
    async function cargar(conversacion: string | null = null) {
        const reunion = reunionId.value;

        if (!reunion && !conversacion) {
            return;
        }

        try {
            const respuesta = await http.getClient().request({
                method: 'get',
                url: cargarMensajes.url({
                    query: reunion ? { reunion } : { conversacion },
                }),
                headers: { Accept: 'application/json' },
            });

            // Si mientras cargaba cambió la reunión, esta respuesta ya no sirve.
            if (reunion !== reunionId.value) {
                return;
            }

            const datos = JSON.parse(respuesta.data) as {
                conversacion_id: string | null;
                mensajes: MensajeChat[];
            };

            conversacionId.value = datos.conversacion_id;
            mensajes.value = datos.mensajes;
        } catch {
            error.value = 'No se pudo cargar la conversación.';
        }
    }

    function nueva() {
        if (enviando.value) {
            return;
        }

        conversacionId.value = null;
        mensajes.value = [];
        error.value = null;
    }

    /**
     * Devuelve false si el mensaje no llegó a Jarvis, para que la vista no
     * pierda el texto ni el documento que el usuario preparó.
     */
    async function enviar(texto: string, documento: File | null = null) {
        const mensaje =
            texto.trim() || (documento ? MENSAJE_SOLO_DOCUMENTO : '');

        if (!mensaje || enviando.value) {
            return false;
        }

        enviando.value = true;
        error.value = null;
        const ahora = new Date().toISOString();
        mensajes.value.push({
            rol: 'usuario',
            contenido: mensaje,
            documento: documento?.name,
            creado_en: ahora,
        });
        mensajes.value.push({
            rol: 'jarvis',
            contenido: '',
            creado_en: ahora,
            actividad: documento ? 'Leyendo el documento…' : 'Pensando…',
        });
        // Se trabaja sobre el objeto reactivo del arreglo para que la vista se actualice.
        const respuesta = mensajes.value[mensajes.value.length - 1];

        const datos = new FormData();
        datos.append('mensaje', mensaje);

        if (reunionId.value) {
            datos.append('reunion_id', reunionId.value);
        }

        if (conversacionId.value) {
            datos.append('conversacion_id', conversacionId.value);
        }

        if (documento) {
            datos.append('documento', documento);
        }

        let enviado = false;

        try {
            const respuestaHttp = await fetch(enviarMensaje.url(), {
                method: 'POST',
                headers: {
                    Accept: 'text/event-stream',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': tokenXsrf(),
                },
                body: datos,
            });

            if (!respuestaHttp.ok || !respuestaHttp.body) {
                throw new Error(await mensajeDeFallo(respuestaHttp));
            }

            enviado = true;

            conversacionId.value =
                respuestaHttp.headers.get('X-Conversacion-Id') ||
                conversacionId.value;

            await leerEventos(respuestaHttp.body, (evento) => {
                switch (evento.type) {
                    case 'stream_start':
                        // Tras usar una herramienta empieza un tramo nuevo de texto.
                        if (respuesta.contenido) {
                            respuesta.contenido += '\n\n';
                        }

                        break;
                    case 'text_delta':
                        respuesta.actividad = undefined;
                        respuesta.contenido += evento.delta ?? '';
                        break;
                    case 'tool_call':
                        respuesta.actividad =
                            ACTIVIDADES[evento.tool_name ?? ''] ??
                            'Trabajando…';
                        break;
                    case 'error':
                        throw new Error(
                            evento.message ||
                                'El Asistente IA tuvo un problema.',
                        );
                    default:
                        if (evento.type.includes('search')) {
                            respuesta.actividad = 'Buscando en internet…';
                        }
                }
            });

            if (!respuesta.contenido.trim()) {
                throw new Error(
                    'El Asistente IA no devolvió ninguna respuesta.',
                );
            }
        } catch (e) {
            error.value =
                e instanceof Error
                    ? e.message
                    : 'El Asistente IA no pudo responder.';

            if (!enviado) {
                // No llegó a Jarvis: se quitan los dos mensajes y la vista conserva lo escrito.
                mensajes.value.splice(-2);
            } else if (!respuesta.contenido.trim()) {
                mensajes.value.pop();
            }
        } finally {
            respuesta.actividad = undefined;
            enviando.value = false;
        }

        return enviado;
    }

    function reiniciar() {
        conversacionId.value = null;
        mensajes.value = [];
        error.value = null;
        enviando.value = false;
    }

    watch(
        reunionId,
        () => {
            reiniciar();
            void cargar();
        },
        { immediate: true },
    );

    return {
        mensajes,
        conversacionId,
        enviando,
        error,
        nueva,
        enviar,
        recargar: cargar,
    };
}

async function mensajeDeFallo(respuesta: Response): Promise<string> {
    if (respuesta.status === 413) {
        return 'El documento es demasiado grande para el servidor.';
    }

    if (respuesta.status === 422) {
        const datos = (await respuesta.json().catch(() => null)) as {
            message?: string;
        } | null;

        return datos?.message || 'Revisa el mensaje o el documento.';
    }

    return respuesta.status === 429
        ? 'Demasiados mensajes seguidos. Espera un momento.'
        : respuesta.status === 419
          ? 'La sesión expiró. Recarga la página.'
          : 'El Asistente IA no pudo responder. Intenta de nuevo.';
}

async function leerEventos(
    cuerpo: ReadableStream<Uint8Array>,
    alRecibir: (evento: EventoStream) => void,
) {
    const lector = cuerpo.getReader();
    const decodificador = new TextDecoder();
    let pendiente = '';

    for (;;) {
        const { done, value } = await lector.read();

        if (done) {
            return;
        }

        pendiente += decodificador.decode(value, { stream: true });
        const eventos = pendiente.split('\n\n');
        pendiente = eventos.pop() ?? '';

        for (const evento of eventos) {
            const datos = evento
                .split('\n')
                .filter((linea) => linea.startsWith('data: '))
                .map((linea) => linea.slice(6))
                .join('');

            if (!datos || datos === '[DONE]') {
                continue;
            }

            try {
                alRecibir(JSON.parse(datos) as EventoStream);
            } catch (e) {
                // Los eventos de error se relanzan; un JSON mal formado se ignora.
                if (!(e instanceof SyntaxError)) {
                    throw e;
                }
            }
        }
    }
}
