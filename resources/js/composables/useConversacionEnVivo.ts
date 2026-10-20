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
    response_id?: string;
    response?: { id?: string; metadata?: Record<string, string> | null };
    error?: { message?: string };
};

type Opciones = {
    voz: string;
    reunionId: string | null;
    // Se lee cada vez: el chat puede crear su conversación en medio de la voz (al adjuntar un documento).
    conversacionId: () => string | null;
};

// Lo que se le pasa a la voz de una respuesta escrita (el resumen de un documento, por ejemplo).
const MAX_CARACTERES_RESPUESTA = 6000;

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
    // Micrófono silenciado: la voz sigue conectada, pero no escucha al usuario.
    const silenciado = ref(false);
    // Si se le puede interrumpir hablando. Si no, el micrófono se cierra
    // mientras habla: así su propia voz (el eco de los parlantes) no vuelve a
    // entrar como si la hubiera dicho el usuario.
    const interrumpible = ref(true);
    let vozSonando = false;
    let finDeVoz: number | undefined;

    let conexion: RTCPeerConnection | null = null;
    let canal: RTCDataChannel | null = null;
    let microfono: MediaStream | null = null;
    let parlante: HTMLAudioElement | null = null;
    let opciones: Opciones | null = null;
    let herramientasPendientes = 0;
    // Pedir una respuesta mientras hay otra en curso da error: se espera a que termine.
    let respuestaEnCurso = false;
    let respuestaPendiente = false;
    let relevoPendiente = false;
    // La respuesta en la que la voz dice lo que ya respondió el chat escrito:
    // se escucha, pero no se agrega otro mensaje (la respuesta ya está escrita).
    let respuestaRelevo: string | null = null;
    // Lo que la voz está diciendo en la respuesta en curso, y lo que se descartó por quedar viejo.
    const itemsDeLaRespuesta = new Set<string>();
    const descartados = new Set<string>();
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

    function quitarMensaje(id: string) {
        mensajes.value = mensajes.value.filter((m) => m.id !== id);
    }

    function esRelevo(evento: EventoRealtime): boolean {
        return (
            respuestaRelevo !== null && evento.response_id === respuestaRelevo
        );
    }

    function enviar(evento: object) {
        if (canal?.readyState === 'open') {
            canal.send(JSON.stringify(evento));
        }
    }

    function pedirRespuesta(relevo = false) {
        if (respuestaEnCurso) {
            respuestaPendiente = true;
            relevoPendiente ||= relevo;

            return;
        }

        respuestaEnCurso = true;
        enviar(
            relevo
                ? {
                      type: 'response.create',
                      response: { metadata: { relevo: 'si' } },
                  }
                : { type: 'response.create' },
        );
    }

    function queHizo(texto: string, documento: string | null): string {
        return documento
            ? `adjuntó el documento «${documento}»${texto ? ` con el mensaje «${texto}»` : ''}`
            : `escribió: «${texto}»`;
    }

    function avisar(texto: string) {
        enviar({
            type: 'conversation.item.create',
            item: {
                type: 'message',
                role: 'user',
                content: [{ type: 'input_text', text: texto }],
            },
        });
    }

    /**
     * Al enviar algo por el chat: si la voz estaba respondiendo, eso quedó
     * viejo (por ejemplo, "envíame el PDF" cuando ya se envió), así que se
     * corta y se quita. La voz sabe que el chat está respondiendo y espera.
     */
    function avisarEnvioEscrito(texto: string, documento: string | null) {
        if (canal?.readyState !== 'open') {
            return;
        }

        ultimaActividad = Date.now();

        if (
            respuestaEnCurso &&
            itemsDeLaRespuesta.size &&
            herramientasPendientes === 0
        ) {
            enviar({ type: 'response.cancel' });
            enviar({ type: 'output_audio_buffer.clear' });
            itemsDeLaRespuesta.forEach((id) => {
                descartados.add(id);
                quitarMensaje(id);
            });
            itemsDeLaRespuesta.clear();
        }

        avisar(
            `[Aviso del chat, no dicho en voz] El usuario ${queHizo(texto, documento)}. ` +
                'El chat escrito le está respondiendo en pantalla; en unos segundos te llega su respuesta. ' +
                'Si te habla mientras tanto, dile que lo estás revisando.',
        );
    }

    /**
     * Le cuenta a la voz lo que el usuario escribió o adjuntó en el chat y lo
     * que respondió el chat escrito (que lee los documentos completos), para
     * que lo comente sin cortar la conversación.
     */
    function contarMensajeEscrito(
        texto: string,
        documento: string | null,
        respuesta: string,
    ) {
        if (canal?.readyState !== 'open') {
            return;
        }

        ultimaActividad = Date.now();
        avisar(
            `[Aviso del chat, no dicho en voz] Esta es tu respuesta a lo que el usuario ${queHizo(texto, documento)}; ` +
                'ya está escrita en pantalla. Dísela ahora en voz alta, con las mismas ideas y en el mismo orden, ' +
                'de forma natural: sin leer símbolos, negritas ni enlaces. Si es larga, di lo principal y avisa que ' +
                'el resto está en pantalla. No digas que viene del chat ni que la revisaste: es tu propia respuesta.\n\n' +
                respuesta.slice(0, MAX_CARACTERES_RESPUESTA),
        );
        pedirRespuesta(true);
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
                    reunion_id: opciones?.reunionId,
                    conversacion_id: opciones?.conversacionId(),
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
            pedirRespuesta();
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
                    const texto = (evento.transcript ?? '').trim();

                    // Un golpe o eco que no resultó en palabras no queda como mensaje.
                    if (texto) {
                        mensaje(evento.item_id, 'usuario').contenido = texto;
                    } else {
                        quitarMensaje(evento.item_id);
                    }
                }

                break;
            case 'response.output_audio_transcript.delta':
            case 'response.audio_transcript.delta':
                if (evento.item_id && !descartados.has(evento.item_id)) {
                    itemsDeLaRespuesta.add(evento.item_id);

                    if (!esRelevo(evento)) {
                        mensaje(evento.item_id, 'jarvis').contenido +=
                            evento.delta ?? '';
                    }
                }

                break;
            case 'response.output_audio_transcript.done':
            case 'response.audio_transcript.done':
                if (
                    evento.item_id &&
                    evento.transcript &&
                    !descartados.has(evento.item_id) &&
                    !esRelevo(evento)
                ) {
                    mensaje(evento.item_id, 'jarvis').contenido =
                        evento.transcript.trim();
                }

                break;
            case 'response.created':
                respuestaEnCurso = true;

                if (evento.response?.metadata?.relevo && evento.response.id) {
                    respuestaRelevo = evento.response.id;
                }

                break;
            case 'response.done':
                respuestaEnCurso = false;
                itemsDeLaRespuesta.clear();

                if (evento.response?.id === respuestaRelevo) {
                    respuestaRelevo = null;
                }

                if (respuestaPendiente) {
                    const relevo = relevoPendiente;
                    respuestaPendiente = false;
                    relevoPendiente = false;
                    pedirRespuesta(relevo);
                }

                break;
            case 'response.function_call_arguments.done':
                void usarHerramienta(evento);
                break;
            case 'output_audio_buffer.started':
                estado.value = 'hablando';
                window.clearTimeout(finDeVoz);
                vozSonando = true;
                aplicarMicrofono();
                break;
            case 'output_audio_buffer.stopped':
            case 'output_audio_buffer.cleared':
                if (herramientasPendientes === 0) {
                    estado.value = 'escuchando';
                }

                // Se espera un poco: la sala sigue sonando un instante después de que calla.
                window.clearTimeout(finDeVoz);
                finDeVoz = window.setTimeout(() => {
                    vozSonando = false;
                    aplicarMicrofono();
                }, 400);
                break;
            case 'error':
                error.value =
                    evento.error?.message ??
                    'Error en la conversación en vivo.';
                break;
        }
    }

    /**
     * Abre el micrófono con cancelación de eco. Si otra parte de la página
     * todavía tiene el micrófono abierto sin ella (la grabación de la reunión
     * mientras se pausa), el navegador puede entregarlo sin cancelación de
     * eco: en ese caso se suelta y se vuelve a pedir.
     */
    async function abrirMicrofono(): Promise<MediaStream> {
        const restricciones = {
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
            },
        };

        for (let intento = 0; ; intento++) {
            const flujo =
                await navigator.mediaDevices.getUserMedia(restricciones);
            const ajustes = flujo.getAudioTracks()[0]?.getSettings();

            if (ajustes?.echoCancellation !== false || intento >= 2) {
                return flujo;
            }

            flujo.getTracks().forEach((pista) => pista.stop());
            await new Promise((listo) => window.setTimeout(listo, 400));
        }
    }

    // El micrófono escucha salvo que esté silenciado o, sin interrupciones, mientras habla.
    function aplicarMicrofono() {
        const pista = microfono?.getAudioTracks()[0];

        if (pista) {
            pista.enabled =
                !silenciado.value && (interrumpible.value || !vozSonando);
        }
    }

    function cerrarConexion() {
        window.clearInterval(vigilante);
        window.clearTimeout(finDeVoz);
        vozSonando = false;
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
        silenciado.value = false;
        parlante = null;
        herramientasPendientes = 0;
        respuestaEnCurso = false;
        respuestaPendiente = false;
        itemsDeLaRespuesta.clear();
        descartados.clear();
        relevoPendiente = false;
        respuestaRelevo = null;
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
            microfono = await abrirMicrofono();

            const respuesta = await http.getClient().request({
                method: 'post',
                url: abrirSesion.url(),
                data: {
                    voz: datos.voz,
                    reunion_id: datos.reunionId,
                    conversacion_id: datos.conversacionId(),
                },
                headers: { Accept: 'application/json' },
            });
            const { llave, minutos_inactividad, interrumpir } = enJson<{
                llave: string;
                minutos_inactividad: number;
                interrumpir: boolean;
            }>(respuesta);
            minutosInactividad = minutos_inactividad;
            interrumpible.value = interrumpir;

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
            .map((m) => ({
                rol: m.rol,
                contenido: m.contenido.trim(),
                creado_en: m.creado_en,
            }));

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
                    conversacion_id: opciones.conversacionId(),
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

    function alternarSilencio() {
        const pista = microfono?.getAudioTracks()[0];

        if (!pista) {
            return;
        }

        silenciado.value = !silenciado.value;
        aplicarMicrofono();
        ultimaActividad = Date.now();
    }

    onBeforeUnmount(() => {
        sesion++;
        cerrarConexion();
    });

    return {
        estado,
        actividad,
        mensajes,
        error,
        iniciar,
        terminar,
        silenciado,
        interrumpible,
        alternarSilencio,
        avisarEnvioEscrito,
        contarMensajeEscrito,
    };
}
