import { http } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
import {
    guardar as guardarConversacion,
    herramienta as ejecutarHerramienta,
    sesion as abrirSesion,
} from '@/actions/App/Http/Controllers/ConversacionEnVivoController';
import { ACTIVIDADES } from '@/composables/useChat';

export type EstadoEnVivo =
    | 'apagado'
    | 'conectando'
    | 'escuchando'
    | 'pensando'
    | 'hablando'
    | 'guardando';

export type MensajeEnVivo = {
    id: string;
    rol: 'usuario' | 'jarvis';
    contenido: string;
    creado_en: string;
};

type EventoRealtime = {
    type: string;
    item_id?: string;
    delta?: string;
    transcript?: string;
    call_id?: string;
    name?: string;
    arguments?: string;
    error?: { message?: string };
};

type Opciones = {
    voz: string;
    reunionId: string | null;
    conversacionId: string | null;
};

const URL_LLAMADAS = 'https://api.openai.com/v1/realtime/calls';

function enJson<T>(respuesta: { data: string }): T {
    return JSON.parse(respuesta.data) as T;
}

/**
 * Conversación por voz en vivo con el asistente (OpenAI Realtime por WebRTC):
 * el usuario habla, el modelo responde con voz y se le puede interrumpir.
 * Cuando el modelo pide una herramienta (buscar en internet, reuniones…), la
 * ejecuta el servidor. Al terminar, lo conversado se guarda en el chat.
 */
export function useConversacionEnVivo(
    // Se llama al guardar lo conversado, también si se cerró sola por inactividad.
    alGuardar: (conversacionId: string) => Promise<void> | void,
) {
    const estado = ref<EstadoEnVivo>('apagado');
    const actividad = ref<string | null>(null);
    const mensajes = ref<MensajeEnVivo[]>([]);
    const error = ref<string | null>(null);

    let conexion: RTCPeerConnection | null = null;
    let canal: RTCDataChannel | null = null;
    let microfono: MediaStream | null = null;
    let parlante: HTMLAudioElement | null = null;
    let opciones: Opciones | null = null;
    let herramientasPendientes = 0;
    let ultimaActividad = 0;
    let minutosInactividad = 3;
    let vigilante: number | undefined;
    // Distingue la conversación vigente de una que se cerró mientras conectaba.
    let sesion = 0;

    function mensaje(id: string, rol: MensajeEnVivo['rol']): MensajeEnVivo {
        let existente = mensajes.value.find((m) => m.id === id);

        if (!existente) {
            mensajes.value.push({
                id,
                rol,
                contenido: '',
                creado_en: new Date().toISOString(),
            });
            existente = mensajes.value[mensajes.value.length - 1];
        }

        return existente;
    }

    function enviar(evento: object) {
        if (canal?.readyState === 'open') {
            canal.send(JSON.stringify(evento));
        }
    }

    async function usarHerramienta(evento: EventoRealtime) {
        herramientasPendientes++;
        estado.value = 'pensando';
        actividad.value = ACTIVIDADES[evento.name ?? ''] ?? 'Trabajando…';
        const numeroSesion = sesion;
        let resultado: string;

        try {
            const respuesta = await http.getClient().request({
                method: 'post',
                url: ejecutarHerramienta.url(),
                data: {
                    nombre: evento.name,
                    argumentos: JSON.parse(evento.arguments || '{}'),
                },
                headers: { Accept: 'application/json' },
            });
            resultado = enJson<{ resultado: string }>(respuesta).resultado;
        } catch {
            resultado = 'La herramienta no respondió.';
        }

        if (numeroSesion !== sesion) {
            return;
        }

        enviar({
            type: 'conversation.item.create',
            item: {
                type: 'function_call_output',
                call_id: evento.call_id,
                output: resultado,
            },
        });
        herramientasPendientes--;

        // Con todos los resultados listos, el modelo sigue hablando.
        if (herramientasPendientes === 0) {
            actividad.value = null;
            enviar({ type: 'response.create' });
        }
    }

    function alRecibir(evento: EventoRealtime) {
        ultimaActividad = Date.now();

        switch (evento.type) {
            case 'session.created':
            case 'session.updated':
                if (estado.value === 'conectando') {
                    estado.value = 'escuchando';
                }

                break;
            // Al empezar a hablar se reserva el mensaje, así queda antes de la respuesta.
            case 'input_audio_buffer.speech_started':
                if (evento.item_id) {
                    mensaje(evento.item_id, 'usuario');
                }

                break;
            case 'conversation.item.input_audio_transcription.delta':
                if (evento.item_id) {
                    mensaje(evento.item_id, 'usuario').contenido +=
                        evento.delta ?? '';
                }

                break;
            case 'conversation.item.input_audio_transcription.completed':
                if (evento.item_id) {
                    mensaje(evento.item_id, 'usuario').contenido = (
                        evento.transcript ?? ''
                    ).trim();
                }

                break;
            case 'response.output_audio_transcript.delta':
            case 'response.audio_transcript.delta':
                if (evento.item_id) {
                    mensaje(evento.item_id, 'jarvis').contenido +=
                        evento.delta ?? '';
                }

                break;
            case 'response.output_audio_transcript.done':
            case 'response.audio_transcript.done':
                if (evento.item_id && evento.transcript) {
                    mensaje(evento.item_id, 'jarvis').contenido =
                        evento.transcript.trim();
                }

                break;
            case 'response.function_call_arguments.done':
                void usarHerramienta(evento);
                break;
            case 'output_audio_buffer.started':
                estado.value = 'hablando';
                break;
            case 'output_audio_buffer.stopped':
            case 'output_audio_buffer.cleared':
                if (herramientasPendientes === 0) {
                    estado.value = 'escuchando';
                }

                break;
            case 'error':
                error.value =
                    evento.error?.message ??
                    'Error en la conversación en vivo.';
                break;
        }
    }

    function cerrarConexion() {
        window.clearInterval(vigilante);
        canal?.close();
        conexion?.close();
        microfono?.getTracks().forEach((pista) => pista.stop());

        if (parlante) {
            parlante.pause();
            parlante.srcObject = null;
        }

        canal = null;
        conexion = null;
        microfono = null;
        parlante = null;
        herramientasPendientes = 0;
        actividad.value = null;
    }

    /**
     * Abre la conversación: pide la llave al servidor, el micrófono al
     * navegador y se conecta con OpenAI.
     */
    async function iniciar(datos: Opciones) {
        if (estado.value !== 'apagado') {
            return;
        }

        const numeroSesion = ++sesion;
        opciones = datos;
        mensajes.value = [];
        error.value = null;
        estado.value = 'conectando';

        try {
            microfono = await navigator.mediaDevices.getUserMedia({
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                },
            });

            const respuesta = await http.getClient().request({
                method: 'post',
                url: abrirSesion.url(),
                data: { voz: datos.voz, reunion_id: datos.reunionId },
                headers: { Accept: 'application/json' },
            });
            const { llave, minutos_inactividad } = enJson<{
                llave: string;
                minutos_inactividad: number;
            }>(respuesta);
            minutosInactividad = minutos_inactividad;

            if (numeroSesion !== sesion) {
                return;
            }

            const pc = new RTCPeerConnection();
            conexion = pc;
            parlante = new Audio();
            parlante.autoplay = true;
            pc.ontrack = (evento) => {
                if (parlante) {
                    parlante.srcObject = evento.streams[0];
                }
            };
            pc.addTrack(microfono.getAudioTracks()[0]);
            pc.onconnectionstatechange = () => {
                if (
                    numeroSesion === sesion &&
                    ['failed', 'disconnected'].includes(pc.connectionState)
                ) {
                    error.value = 'Se cortó la conversación en vivo.';
                    void terminar();
                }
            };

            canal = pc.createDataChannel('oai-events');
            canal.onmessage = (evento) => {
                try {
                    alRecibir(JSON.parse(evento.data) as EventoRealtime);
                } catch {
                    // Un mensaje ilegible no corta la conversación.
                }
            };
            canal.onopen = () => {
                estado.value = 'escuchando';
            };

            const oferta = await pc.createOffer();
            await pc.setLocalDescription(oferta);

            const respuestaOpenAi = await fetch(URL_LLAMADAS, {
                method: 'POST',
                body: oferta.sdp,
                headers: {
                    Authorization: `Bearer ${llave}`,
                    'Content-Type': 'application/sdp',
                },
            });

            if (!respuestaOpenAi.ok) {
                throw new Error(`OpenAI respondió ${respuestaOpenAi.status}`);
            }

            if (numeroSesion !== sesion) {
                return;
            }

            await pc.setRemoteDescription({
                type: 'answer',
                sdp: await respuestaOpenAi.text(),
            });

            // Sin hablar un rato, se cierra sola para no seguir cobrando.
            ultimaActividad = Date.now();
            vigilante = window.setInterval(() => {
                const quieto =
                    estado.value === 'escuchando' &&
                    Date.now() - ultimaActividad >
                        minutosInactividad * 60 * 1000;

                if (quieto) {
                    void terminar();
                }
            }, 15_000);
        } catch (e) {
            if (numeroSesion !== sesion) {
                return;
            }

            cerrarConexion();
            estado.value = 'apagado';
            error.value =
                e instanceof Error && e.name === 'NotAllowedError'
                    ? 'El navegador no tiene permiso para usar el micrófono.'
                    : e instanceof Error && e.name === 'NotFoundError'
                      ? 'No se encontró ningún micrófono.'
                      : 'No se pudo iniciar la conversación en vivo.';
        }
    }

    /**
     * Cierra la conversación y guarda lo dicho en el chat. Devuelve el ID de
     * la conversación donde quedó (o null si no se guardó nada).
     */
    async function terminar(): Promise<string | null> {
        if (estado.value === 'apagado' || estado.value === 'guardando') {
            return null;
        }

        sesion++;
        cerrarConexion();

        const dichos = mensajes.value
            .filter((m) => m.contenido.trim())
            .map((m) => ({ rol: m.rol, contenido: m.contenido.trim() }));

        if (!dichos.length || !opciones) {
            estado.value = 'apagado';
            mensajes.value = [];

            return null;
        }

        estado.value = 'guardando';

        try {
            const respuesta = await http.getClient().request({
                method: 'post',
                url: guardarConversacion.url(),
                data: {
                    reunion_id: opciones.reunionId,
                    conversacion_id: opciones.conversacionId,
                    mensajes: dichos,
                },
                headers: { Accept: 'application/json' },
            });
            const id = enJson<{ conversacion_id: string }>(
                respuesta,
            ).conversacion_id;
            // Primero se recarga el chat con lo guardado y recién ahí se quita lo provisional.
            await alGuardar(id);
            mensajes.value = [];

            return id;
        } catch {
            error.value =
                'No se pudo guardar la conversación en el chat. Queda visible hasta recargar la página.';

            return null;
        } finally {
            estado.value = 'apagado';
        }
    }

    onBeforeUnmount(() => {
        sesion++;
        cerrarConexion();
    });

    return { estado, actividad, mensajes, error, iniciar, terminar };
}
