import { http } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
import TranscripcionEnVivoController from '@/actions/App/Http/Controllers/TranscripcionEnVivoController';

export type LineaEnVivo = {
    id: string;
    texto: string;
    final: boolean;
};

export type EstadoEnVivo = 'apagado' | 'conectando' | 'activo' | 'error';

type EventoRealtime = {
    type: string;
    item_id?: string;
    delta?: string;
    transcript?: string;
    error?: { message?: string };
};

const URL_LLAMADAS = 'https://api.openai.com/v1/realtime/calls';
const MAX_LINEAS = 200;
const MAX_RECONEXIONES = 3;

type Opciones = {
    // Se llama con cada frase ya transcrita por completo.
    alCompletarFrase?: (texto: string) => void;
};

/**
 * Transcripción en vivo con OpenAI Realtime: el navegador envía el micrófono
 * por WebRTC directamente a OpenAI (con una llave temporal que entrega el
 * servidor) y recibe el texto mientras se habla. Se usa para detectar las
 * órdenes de voz; si falla, la grabación de la reunión sigue igual.
 */
export function useTranscripcionEnVivo(opciones: Opciones = {}) {
    const lineas = ref<LineaEnVivo[]>([]);
    const estado = ref<EstadoEnVivo>('apagado');
    const mensaje = ref<string | null>(null);

    let conexion: RTCPeerConnection | null = null;
    let pista: MediaStreamTrack | null = null;
    let reunionActual: string | null = null;
    let microfono: MediaStream | null = null;
    let reconexiones = 0;
    // Distingue la sesión vigente de una que se cerró mientras conectaba.
    let sesion = 0;

    function linea(id: string): LineaEnVivo {
        let existente = lineas.value.find((l) => l.id === id);

        if (!existente) {
            lineas.value.push({ id, texto: '', final: false });
            lineas.value = lineas.value.slice(-MAX_LINEAS);
            existente = lineas.value[lineas.value.length - 1];
        }

        return existente;
    }

    function alRecibir(evento: EventoRealtime) {
        switch (evento.type) {
            // Al empezar a hablar se reserva la línea, así el orden no depende
            // de qué frase termina de transcribirse primero.
            case 'input_audio_buffer.speech_started':
                if (evento.item_id) {
                    linea(evento.item_id);
                }

                break;
            case 'conversation.item.input_audio_transcription.delta':
                if (evento.item_id) {
                    linea(evento.item_id).texto += evento.delta ?? '';
                }

                break;
            case 'conversation.item.input_audio_transcription.completed':
                if (evento.item_id) {
                    const actual = linea(evento.item_id);
                    actual.texto = (evento.transcript ?? actual.texto).trim();
                    actual.final = true;

                    if (actual.texto) {
                        opciones.alCompletarFrase?.(actual.texto);
                    }
                }

                // Las frases sin texto (ruido, silencio) no se muestran.
                lineas.value = lineas.value.filter(
                    (l) => !l.final || l.texto !== '',
                );
                break;
            case 'error':
                mensaje.value =
                    evento.error?.message ?? 'Error en las órdenes de voz.';
                break;
        }
    }

    async function pedirLlave(reunionId: string): Promise<string> {
        const respuesta = await http.getClient().request({
            method: 'post',
            url: TranscripcionEnVivoController.url(reunionId),
            headers: { Accept: 'application/json' },
        });

        return (JSON.parse(respuesta.data) as { llave: string }).llave;
    }

    function cerrarConexion() {
        conexion?.close();
        pista?.stop();
        conexion = null;
        pista = null;
    }

    async function abrir(numeroSesion: number) {
        if (!reunionActual || !microfono) {
            return;
        }

        estado.value = 'conectando';

        try {
            const llave = await pedirLlave(reunionActual);

            if (numeroSesion !== sesion) {
                return;
            }

            const pc = new RTCPeerConnection();
            // Pista propia: cerrar la transcripción no corta la grabación.
            pista = microfono.getAudioTracks()[0].clone();
            pc.addTrack(pista);

            const canal = pc.createDataChannel('oai-events');
            canal.onmessage = (evento) => {
                try {
                    alRecibir(JSON.parse(evento.data) as EventoRealtime);
                } catch {
                    // Un mensaje ilegible no detiene la transcripción.
                }
            };
            canal.onopen = () => {
                estado.value = 'activo';
                mensaje.value = null;
                reconexiones = 0;
            };

            pc.onconnectionstatechange = () => {
                if (
                    numeroSesion === sesion &&
                    ['failed', 'disconnected'].includes(pc.connectionState)
                ) {
                    void reconectar(numeroSesion);
                }
            };

            conexion = pc;

            const oferta = await pc.createOffer();
            await pc.setLocalDescription(oferta);

            const respuesta = await fetch(URL_LLAMADAS, {
                method: 'POST',
                body: oferta.sdp,
                headers: {
                    Authorization: `Bearer ${llave}`,
                    'Content-Type': 'application/sdp',
                },
            });

            if (!respuesta.ok) {
                throw new Error(`OpenAI respondió ${respuesta.status}`);
            }

            if (numeroSesion !== sesion) {
                cerrarConexion();

                return;
            }

            await pc.setRemoteDescription({
                type: 'answer',
                sdp: await respuesta.text(),
            });
        } catch {
            if (numeroSesion !== sesion) {
                return;
            }

            cerrarConexion();
            estado.value = 'error';
            mensaje.value =
                'No se pudieron activar las órdenes de voz. La reunión se sigue grabando normalmente.';
        }
    }

    async function reconectar(numeroSesion: number) {
        cerrarConexion();

        if (reconexiones >= MAX_RECONEXIONES) {
            estado.value = 'error';
            mensaje.value =
                'Se perdió la conexión de las órdenes de voz. La reunión se sigue grabando normalmente.';

            return;
        }

        reconexiones++;
        await abrir(numeroSesion);
    }

    async function conectar(reunionId: string, stream: MediaStream) {
        desconectar();
        reunionActual = reunionId;
        microfono = stream;
        reconexiones = 0;
        await abrir(++sesion);
    }

    function desconectar() {
        sesion++;
        cerrarConexion();
        reunionActual = null;
        microfono = null;
        estado.value = 'apagado';
        mensaje.value = null;
    }

    function limpiar() {
        lineas.value = [];
    }

    onBeforeUnmount(desconectar);

    return { lineas, estado, mensaje, conectar, desconectar, limpiar };
}
