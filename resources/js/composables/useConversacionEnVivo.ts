import { http } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { onBeforeUnmount, ref } from 'vue';
import {
    guardar as guardarConversacion,
    herramienta as ejecutarHerramienta,
    sesion as abrirSesion,
} from '@/actions/App/Http/Controllers/ConversacionEnVivoController';
import { ACTIVIDADES } from '@/composables/useChat';
import { interpretarOrdenVoz } from '@/lib/ordenesVoz';
import { enlacesDe } from '@/lib/enlacesVoz';
import { esRuido } from '@/lib/ruidoVoz';
import { tokenXsrf } from '@/lib/xsrf';

export type EstadoEnVivo =
    | 'apagado'
    | 'conectando'
    | 'escuchando'
    | 'pensando'
    | 'hablando'
    | 'guardando';

export type MensajeEnVivo = {
    id: string;
    // ID con el que se guarda en el chat (el de OpenAI no sirve: no es un UUID).
    uuid: string;
    rol: 'usuario' | 'jarvis';
    contenido: string;
    creado_en: string;
};

type EventoRealtime = {
    type: string;
    item_id?: string;
    delta?: string;
    transcript?: string;
    logprobs?: { logprob: number }[] | null;
    call_id?: string;
    name?: string;
    arguments?: string;
    response_id?: string;
    response?: { id?: string; metadata?: Record<string, string> | null };
    error?: { message?: string };
};

export type ModoEnVivo = 'personal' | 'sala';

type Opciones = {
    voz: string;
    // "sala": reunión con varias personas; solo responde si le dicen "Asistente, …".
    modo?: ModoEnVivo;
    reunionId: string | null;
    // Se lee cada vez: el chat puede crear su conversación en medio de la voz (al adjuntar un documento).
    conversacionId: () => string | null;
    // Cuando lo dicho crea la conversación del chat, para que lo escrito siga en ella.
    alCrearConversacion?: (conversacionId: string) => void;
};

// Con el micrófono silenciado la voz queda en espera mientras la reunión graba;
// si se olvida así, se cierra sola a los 30 minutos.
const LIMITE_SILENCIADA_MS = 30 * 60 * 1000;

// Espera tras terminar una frase antes de guardarla, para juntar varias en un envío.
const ESPERA_GUARDADO_MS = 1500;

// Lo que se le pasa a la voz de una respuesta escrita (el resumen de un documento, por ejemplo).
const MAX_CARACTERES_RESPUESTA = 6000;

// Modo sala: lo que se oye hasta este tiempo después de que el asistente calla
// es el eco de su voz en la sala, no alguien hablando.
const ECO_MS = 1500;

// Modo sala: cuántas frases de la conversación de la sala se le dejan como
// contexto (cada respuesta las vuelve a procesar y se cobran).
const MAX_CONTEXTO_SALA = 30;

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
    // Modo sala: escucha toda la reunión, pero solo habla cuando le dicen "Asistente, …".
    const sala = ref(false);
    let vozSonando = false;
    let finVozEn = 0;
    // Si se pidió parar mientras buscaba: al llegar el resultado no se pone a hablar.
    let callarTrasHerramienta = false;
    const contextoSala: string[] = [];
    // Detección de voz de la sesión; mientras el asistente habla se le sube el
    // umbral, para que lo interrumpa la voz del usuario y no el eco de los parlantes.
    let deteccion: Record<string, unknown> | null = null;
    // Por debajo de esta seguridad del transcriptor, lo oído se toma como ruido.
    let confianzaMinima = 0;
    let umbralHablando = 0.85;
    let umbralSubido = false;
    let finDeVoz: number | undefined;

    let conexion: RTCPeerConnection | null = null;
    let canal: RTCDataChannel | null = null;
    let microfono: MediaStream | null = null;
    // Por donde sale el micrófono hacia OpenAI: al silenciar se le quita la pista.
    let emisor: RTCRtpSender | null = null;
    let parlante: HTMLAudioElement | null = null;
    let opciones: Opciones | null = null;
    let herramientasPendientes = 0;
    // Nivel de la voz (micrófono y voz del asistente), para animar el núcleo.
    let contextoAudio: AudioContext | null = null;
    const analizadores: AnalyserNode[] = [];
    let muestras = new Uint8Array(0);
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

    // Lo dicho se guarda en el chat por partes mientras se habla, así no se
    // pierde si se cierra la página o si el acta se genera antes de terminar.
    // Lo ya guardado (uuid → texto) y las frases que terminaron de decirse:
    const guardados = new Map<string, string>();
    const terminados = new Set<string>();
    let conversacionGuardada: string | null = null;
    let colaGuardado: Promise<unknown> = Promise.resolve();
    let esperaGuardado: number | undefined;

    function porGuardar(todo: boolean) {
        return mensajes.value
            .filter((m) => {
                const texto = m.contenido.trim();

                return (
                    texto &&
                    guardados.get(m.uuid) !== texto &&
                    (todo || terminados.has(m.id))
                );
            })
            .map((m) => ({
                id: m.uuid,
                rol: m.rol,
                contenido: m.contenido.trim(),
                creado_en: m.creado_en,
            }));
    }

    function conversacionActual(): string | null {
        return opciones?.conversacionId() ?? conversacionGuardada;
    }

    async function enviarLoDicho(todo: boolean): Promise<string | null> {
        const dichos = porGuardar(todo);

        if (!dichos.length || !opciones) {
            return conversacionActual();
        }

        const nueva = !conversacionActual();
        const respuesta = await http.getClient().request({
            method: 'post',
            url: guardarConversacion.url(),
            data: {
                reunion_id: opciones.reunionId,
                conversacion_id: conversacionActual(),
                mensajes: dichos,
            },
            headers: { Accept: 'application/json' },
        });
        const id = enJson<{ conversacion_id: string }>(
            respuesta,
        ).conversacion_id;
        conversacionGuardada = id;
        dichos.forEach((dicho) => guardados.set(dicho.id, dicho.contenido));

        if (nueva) {
            opciones.alCrearConversacion?.(id);
        }

        return id;
    }

    /**
     * Guarda lo dicho que falte. De a un envío a la vez, para que dos envíos
     * seguidos no creen dos conversaciones. Con todo=true incluye también la
     * frase que se está diciendo.
     */
    function guardarLoDicho(todo = false): Promise<string | null> {
        const envio = colaGuardado.then(() => enviarLoDicho(todo));
        colaGuardado = envio.catch(() => null);

        return envio;
    }

    function terminoFrase(id: string) {
        terminados.add(id);
        window.clearTimeout(esperaGuardado);
        // Si falla, se reintenta con la frase siguiente o al terminar.
        esperaGuardado = window.setTimeout(
            () => void guardarLoDicho().catch(() => undefined),
            ESPERA_GUARDADO_MS,
        );
    }

    /**
     * Al cerrar o recargar la página: manda lo que falte con keepalive, que el
     * navegador envía aunque la página ya se esté cerrando.
     */
    function guardarAlCerrar() {
        if (estado.value === 'apagado' || !opciones) {
            return;
        }

        const dichos = porGuardar(true);

        if (!dichos.length) {
            return;
        }

        void fetch(guardarConversacion.url(), {
            method: 'POST',
            keepalive: true,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': tokenXsrf(),
            },
            body: JSON.stringify({
                reunion_id: opciones.reunionId,
                conversacion_id: conversacionActual(),
                mensajes: dichos,
            }),
        }).catch(() => undefined);
    }

    function mensaje(id: string, rol: MensajeEnVivo['rol']): MensajeEnVivo {
        let existente = mensajes.value.find((m) => m.id === id);

        if (!existente) {
            mensajes.value.push({
                id,
                uuid: crypto.randomUUID(),
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
            ? `adjuntó ${/\.(jpe?g|png|webp)$/i.test(documento) ? 'la imagen' : 'el documento'} «${documento}»${texto ? ` con el mensaje «${texto}»` : ''}`
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

        // Silenciada no habla (su voz se grabaría en la reunión): solo se entera.
        if (silenciado.value) {
            avisar(
                `[Aviso del chat, no dicho en voz] El usuario ${queHizo(texto, documento)} y el chat ya le respondió ` +
                    'por escrito. Tiene el micrófono silenciado: no lo digas en voz alta, solo tenlo en cuenta.\n\n' +
                    respuesta.slice(0, MAX_CARACTERES_RESPUESTA),
            );

            return;
        }

        avisar(
            `[Aviso del chat, no dicho en voz] Esta es tu respuesta a lo que el usuario ${queHizo(texto, documento)}; ` +
                'ya está escrita en pantalla. Dísela ahora en voz alta, con las mismas ideas y en el mismo orden, ' +
                'de forma natural: sin leer símbolos, negritas ni enlaces. Si es larga, di lo principal y avisa que ' +
                'el resto está en pantalla. No digas que viene del chat ni que la revisaste: es tu propia respuesta.\n\n' +
                respuesta.slice(0, MAX_CARACTERES_RESPUESTA),
        );
        pedirRespuesta(true);
    }

    function ajustarUmbral(hablando: boolean) {
        if (
            !deteccion ||
            deteccion.type !== 'server_vad' ||
            !interrumpible.value ||
            umbralSubido === hablando
        ) {
            return;
        }

        umbralSubido = hablando;
        enviar({
            type: 'session.update',
            session: {
                type: 'realtime',
                audio: {
                    input: {
                        turn_detection: hablando
                            ? { ...deteccion, threshold: umbralHablando }
                            : deteccion,
                    },
                },
            },
        });
    }

    function borrarItem(id: string) {
        enviar({ type: 'conversation.item.delete', item_id: id });
    }

    /**
     * Corta lo que está diciendo (botón "Detener", tecla Esc o "Asistente,
     * para"). Lo que alcanzó a decir queda en el chat.
     */
    function detenerRespuesta() {
        if (!canal) {
            return;
        }

        ultimaActividad = Date.now();
        respuestaPendiente = false;
        relevoPendiente = false;

        if (herramientasPendientes > 0) {
            callarTrasHerramienta = true;
        }

        if (respuestaEnCurso) {
            enviar({ type: 'response.cancel' });
        }

        if (vozSonando) {
            enviar({ type: 'output_audio_buffer.clear' });
        }

        itemsDeLaRespuesta.forEach((id) => {
            if (mensajes.value.some((m) => m.id === id && m.contenido.trim())) {
                terminoFrase(id);
            }
        });

        actividad.value = null;

        if (['pensando', 'hablando'].includes(estado.value)) {
            estado.value = 'escuchando';
        }
    }

    /**
     * Modo sala: decide qué hacer con cada frase de la reunión. Solo responde
     * si empieza con "Asistente, …"; lo demás queda como contexto, y el eco de
     * su propia voz se descarta.
     */
    function atenderEnSala(id: string, texto: string) {
        const orden = esRuido(texto) ? null : interpretarOrdenVoz(texto);

        if (!orden) {
            const eco = vozSonando || Date.now() - finVozEn < ECO_MS;

            if (eco || esRuido(texto)) {
                borrarItem(id);

                return;
            }

            contextoSala.push(id);

            if (contextoSala.length > MAX_CONTEXTO_SALA) {
                borrarItem(contextoSala.shift()!);
            }

            return;
        }

        switch (orden.tipo) {
            case 'detener':
                borrarItem(id);
                detenerRespuesta();
                break;
            case 'terminar':
                void terminar();
                break;
            // La reunión ya está en pausa durante la conversación en vivo.
            case 'pausar':
            case 'reanudar':
                borrarItem(id);
                break;
            case 'pregunta':
                // Una pregunta nueva corta la respuesta anterior.
                if (respuestaEnCurso || vozSonando || herramientasPendientes) {
                    detenerRespuesta();
                }

                mensaje(id, 'usuario').contenido = texto;
                terminoFrase(id);
                pedirRespuesta();
                break;
        }
    }

    function medirNivel(flujo: MediaStream) {
        try {
            contextoAudio ??= new AudioContext();
            void contextoAudio.resume();
            const analizador = contextoAudio.createAnalyser();
            analizador.fftSize = 512;
            analizador.smoothingTimeConstant = 0.6;
            contextoAudio.createMediaStreamSource(flujo).connect(analizador);
            analizadores.push(analizador);
        } catch {
            // Sin nivel de voz el núcleo igual cambia según el estado.
        }
    }

    /** Volumen actual (0 a 1) de lo que se oye: el usuario o el asistente. */
    function nivelVoz(): number {
        let maximo = 0;

        for (const analizador of analizadores) {
            if (muestras.length !== analizador.fftSize) {
                muestras = new Uint8Array(analizador.fftSize);
            }

            analizador.getByteTimeDomainData(muestras);
            let suma = 0;

            for (const valor of muestras) {
                const x = (valor - 128) / 128;
                suma += x * x;
            }

            maximo = Math.max(maximo, Math.sqrt(suma / muestras.length));
        }

        return Math.min(1, maximo * 4);
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

        // Los enlaces quedan escritos en el chat; la voz solo dice los titulares.
        const enlaces = enlacesDe(evento.name, resultado);

        if (enlaces) {
            const escrito = mensaje(`enlaces-${evento.call_id}`, 'jarvis');
            escrito.contenido = enlaces;
            terminoFrase(escrito.id);
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

        // Con todos los resultados listos, el modelo sigue hablando (salvo que le pidieran parar).
        if (herramientasPendientes === 0) {
            actividad.value = null;

            if (callarTrasHerramienta) {
                callarTrasHerramienta = false;
                estado.value = 'escuchando';
            } else {
                pedirRespuesta();
            }
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
            // El mensaje del usuario aparece con el primer texto, no al detectar
            // voz: un ruido no deja un "…" en el chat. La respuesta se pide recién
            // con la frase completa, así que queda igual antes de la respuesta.
            case 'conversation.item.input_audio_transcription.delta':
                if (evento.item_id && !sala.value && evento.delta?.trim()) {
                    mensaje(evento.item_id, 'usuario').contenido +=
                        evento.delta ?? '';
                }

                break;
            case 'conversation.item.input_audio_transcription.completed':
                if (evento.item_id && sala.value) {
                    atenderEnSala(
                        evento.item_id,
                        (evento.transcript ?? '').trim(),
                    );
                } else if (evento.item_id) {
                    const texto = (evento.transcript ?? '').trim();

                    // Un golpe, eco o frase inventada con el ruido no queda ni se responde.
                    if (esRuido(texto, evento.logprobs, confianzaMinima)) {
                        quitarMensaje(evento.item_id);
                        borrarItem(evento.item_id);
                    } else {
                        mensaje(evento.item_id, 'usuario').contenido = texto;
                        terminoFrase(evento.item_id);
                        pedirRespuesta();
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
                    terminoFrase(evento.item_id);
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
                ajustarUmbral(true);
                break;
            case 'output_audio_buffer.stopped':
            case 'output_audio_buffer.cleared':
                if (herramientasPendientes === 0) {
                    estado.value = 'escuchando';
                }

                finVozEn = Date.now();
                // Se espera un poco: la sala sigue sonando un instante después de que calla.
                window.clearTimeout(finDeVoz);
                finDeVoz = window.setTimeout(() => {
                    vozSonando = false;
                    aplicarMicrofono();
                    ajustarUmbral(false);
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
        analizadores.length = 0;
        void contextoAudio?.close();
        contextoAudio = null;

        if (parlante) {
            parlante.pause();
            parlante.srcObject = null;
        }

        canal = null;
        conexion = null;
        microfono = null;
        emisor = null;
        silenciado.value = false;
        parlante = null;
        herramientasPendientes = 0;
        respuestaEnCurso = false;
        respuestaPendiente = false;
        itemsDeLaRespuesta.clear();
        descartados.clear();
        relevoPendiente = false;
        respuestaRelevo = null;
        callarTrasHerramienta = false;
        contextoSala.length = 0;
        deteccion = null;
        umbralSubido = false;
        actividad.value = null;
    }

    async function iniciar(datos: Opciones) {
        if (estado.value !== 'apagado') {
            return;
        }

        const numeroSesion = ++sesion;
        opciones = datos;
        mensajes.value = [];
        guardados.clear();
        terminados.clear();
        conversacionGuardada = null;
        error.value = null;
        estado.value = 'conectando';

        try {
            microfono = await abrirMicrofono();

            const respuesta = await http.getClient().request({
                method: 'post',
                url: abrirSesion.url(),
                data: {
                    voz: datos.voz,
                    modo: datos.modo ?? 'personal',
                    reunion_id: datos.reunionId,
                    conversacion_id: datos.conversacionId(),
                },
                headers: { Accept: 'application/json' },
            });
            const datosSesion = enJson<{
                llave: string;
                minutos_inactividad: number;
                interrumpir: boolean;
                modo: ModoEnVivo;
                deteccion: Record<string, unknown> | null;
                umbral_hablando: number;
                confianza_minima?: number;
            }>(respuesta);
            const { llave, minutos_inactividad, interrumpir, modo } =
                datosSesion;
            deteccion = datosSesion.deteccion;
            umbralHablando = datosSesion.umbral_hablando;
            confianzaMinima = datosSesion.confianza_minima ?? 0;
            minutosInactividad = minutos_inactividad;
            interrumpible.value = interrumpir;
            sala.value = modo === 'sala';

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
                    medirNivel(evento.streams[0]);
                }
            };
            emisor = pc.addTrack(microfono.getAudioTracks()[0]);
            medirNivel(microfono);
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
                // Silenciada queda en espera más tiempo: no envía audio.
                const limite = silenciado.value
                    ? LIMITE_SILENCIADA_MS
                    : minutosInactividad * 60 * 1000;
                const quieto =
                    estado.value === 'escuchando' &&
                    Date.now() - ultimaActividad > limite;

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
        window.clearTimeout(esperaGuardado);

        if (!mensajes.value.some((m) => m.contenido.trim()) || !opciones) {
            estado.value = 'apagado';
            mensajes.value = [];

            return null;
        }

        estado.value = 'guardando';

        try {
            // Lo que ya se guardó por partes no se vuelve a enviar.
            const id = await guardarLoDicho(true);

            if (id) {
                // Primero se recarga el chat con lo guardado y recién ahí se quita lo provisional.
                await alGuardar(id);
            }

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

    /**
     * Silencia o activa el micrófono de la voz. Silenciada, la conversación
     * sigue abierta pero suelta el micrófono y se calla, así la grabación de
     * la reunión puede seguir sin captar su voz. Al activarla, el micrófono se
     * vuelve a abrir con cancelación de eco: la grabación lo abre sin ella y
     * el navegador puede habérsela quitado también a la voz.
     */
    async function alternarSilencio() {
        if (
            !emisor ||
            !['escuchando', 'pensando', 'hablando'].includes(estado.value)
        ) {
            return;
        }

        const numeroSesion = sesion;
        ultimaActividad = Date.now();

        if (!silenciado.value) {
            silenciado.value = true;

            if (respuestaEnCurso || vozSonando) {
                enviar({ type: 'response.cancel' });
                enviar({ type: 'output_audio_buffer.clear' });
            }

            microfono?.getTracks().forEach((pista) => pista.stop());
            microfono = null;
            await emisor.replaceTrack(null);

            return;
        }

        try {
            const flujo = await abrirMicrofono();

            if (numeroSesion !== sesion || !emisor) {
                flujo.getTracks().forEach((pista) => pista.stop());

                return;
            }

            microfono = flujo;
            await emisor.replaceTrack(flujo.getAudioTracks()[0]);
            silenciado.value = false;
            aplicarMicrofono();
        } catch {
            error.value =
                'No se pudo volver a abrir el micrófono. Revisa el permiso del navegador.';
        }
    }

    useEventListener(window, 'pagehide', guardarAlCerrar);

    // Al salir de la página del chat sin terminar la conversación, se guarda igual.
    onBeforeUnmount(() => {
        guardarAlCerrar();
        sesion++;
        window.clearTimeout(esperaGuardado);
        cerrarConexion();
    });

    return {
        estado,
        actividad,
        nivelVoz,
        mensajes,
        error,
        iniciar,
        terminar,
        silenciado,
        interrumpible,
        sala,
        detenerRespuesta,
        alternarSilencio,
        avisarEnvioEscrito,
        contarMensajeEscrito,
    };
}
