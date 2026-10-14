import { http } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import type { Ref } from 'vue';
import {
    enviar as enviarMensaje,
    mensajes as cargarMensajes,
} from '@/actions/App/Http/Controllers/ChatController';

export type MensajeChat = {
    rol: 'usuario' | 'jarvis';
    contenido: string;
    actividad?: string;
};

type EventoStream = {
    type: string;
    delta?: string;
    tool_name?: string;
    message?: string;
};

const ACTIVIDADES: Record<string, string> = {
    LeerPagina: 'Leyendo la página…',
    UltimosVideosYoutube: 'Revisando YouTube…',
    ConsultarReuniones: 'Buscando en las reuniones…',
    LeerReunion: 'Leyendo el acta…',
};

function tokenXsrf(): string {
    const cookie = document.cookie
        .split('; ')
        .find((parte) => parte.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
}

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

    async function cargar() {
        const reunion = reunionId.value;

        if (!reunion) {
            return;
        }

        try {
            const respuesta = await http.getClient().request({
                method: 'get',
                url: cargarMensajes.url({ query: { reunion } }),
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

    async function enviar(texto: string) {
        const mensaje = texto.trim();

        if (!mensaje || enviando.value) {
            return;
        }

        enviando.value = true;
        error.value = null;
        mensajes.value.push({ rol: 'usuario', contenido: mensaje });
        mensajes.value.push({
            rol: 'jarvis',
            contenido: '',
            actividad: 'Pensando…',
        });
        // Se trabaja sobre el objeto reactivo del arreglo para que la vista se actualice.
        const respuesta = mensajes.value[mensajes.value.length - 1];

        try {
            const respuestaHttp = await fetch(enviarMensaje.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'text/event-stream',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': tokenXsrf(),
                },
                body: JSON.stringify({
                    mensaje,
                    reunion_id: reunionId.value,
                    conversacion_id: conversacionId.value,
                }),
            });

            if (!respuestaHttp.ok || !respuestaHttp.body) {
                throw new Error(
                    respuestaHttp.status === 429
                        ? 'Demasiados mensajes seguidos. Espera un momento.'
                        : respuestaHttp.status === 419
                          ? 'La sesión expiró. Recarga la página.'
                          : 'Jarvis no pudo responder. Intenta de nuevo.',
                );
            }

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
                            evento.message || 'Jarvis tuvo un problema.',
                        );
                    default:
                        if (evento.type.includes('search')) {
                            respuesta.actividad = 'Buscando en internet…';
                        }
                }
            });

            if (!respuesta.contenido.trim()) {
                throw new Error('Jarvis no devolvió ninguna respuesta.');
            }
        } catch (e) {
            error.value =
                e instanceof Error ? e.message : 'Jarvis no pudo responder.';

            if (!respuesta.contenido.trim()) {
                mensajes.value.pop();
            }
        } finally {
            respuesta.actividad = undefined;
            enviando.value = false;
        }
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

    return { mensajes, conversacionId, enviando, error, nueva, enviar };
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
