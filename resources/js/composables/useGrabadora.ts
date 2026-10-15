import { http, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { store as subirFragmento } from '@/actions/App/Http/Controllers/FragmentoAudioController';
import {
    store as crearReunion,
    pausar as pausarReunion,
    reanudar as reanudarReunion,
    terminar as terminarReunion,
} from '@/actions/App/Http/Controllers/ReunionController';
import type { ConfigGrabacion, Reunion } from '@/types';

export type EstadoGrabadora =
    | 'inactivo'
    | 'iniciando'
    | 'escuchando'
    | 'pausando'
    | 'pausado'
    | 'reanudando'
    | 'terminando'
    | 'subida-pendiente';

// Estados en los que salir de la página cortaría una grabación o una subida.
const ESTADOS_OCUPADOS: EstadoGrabadora[] = [
    'iniciando',
    'escuchando',
    'pausando',
    'reanudando',
    'terminando',
    'subida-pendiente',
];

export type EstadoSubida = 'pendiente' | 'subiendo' | 'subido' | 'error';

export type Subida = {
    orden: number;
    duracion: number;
    tamano: number;
    estado: EstadoSubida;
    intentos: number;
    error?: string;
};

type FragmentoEnCola = Subida & { blob: Blob };

type RespuestaHttp = { status: number; data: string };

const MAX_INTENTOS = 3;
const ESPERA_REINTENTO_MS = [2_000, 5_000, 10_000];

// Chrome y Edge graban WebM/Opus. Safari solo MP4.
const FORMATOS = [
    'audio/webm;codecs=opus',
    'audio/ogg;codecs=opus',
    'audio/mp4',
];

const esperar = (ms: number) =>
    new Promise((resolve) => window.setTimeout(resolve, ms));

function leerJson<T>(respuesta: RespuestaHttp): T | null {
    try {
        return JSON.parse(respuesta.data) as T;
    } catch {
        return null;
    }
}

function respuestaDeError(error: unknown): RespuestaHttp | null {
    if (error && typeof error === 'object' && 'response' in error) {
        return (error as { response: RespuestaHttp }).response;
    }

    return null;
}

async function post<T>(url: string, data?: FormData | object): Promise<T> {
    const respuesta = await http.getClient().request({
        method: 'post',
        url,
        data,
        headers: { Accept: 'application/json' },
    });

    return leerJson<T>(respuesta) as T;
}

/**
 * Graba el micrófono en fragmentos independientes (cada uno es un archivo de
 * audio completo) y los sube en orden al servidor mientras la reunión sigue.
 * Una reunión puede pausarse y reanudarse varias veces antes de terminarla.
 *
 * @param abierta Reunión que quedó abierta (en pausa o tras cerrar el navegador).
 */
export function useGrabadora(config: ConfigGrabacion, abierta: Reunion | null) {
    const estado = ref<EstadoGrabadora>('inactivo');
    const reunion = ref<Reunion | null>(null);
    const subidas = ref<Subida[]>([]);
    const error = ref<string | null>(null);
    const analizador = shallowRef<AnalyserNode | null>(null);
    // Micrófono activo, para compartirlo con la transcripción en vivo.
    const microfono = shallowRef<MediaStream | null>(null);
    const ahora = ref(Date.now());

    let stream: MediaStream | null = null;
    let contextoAudio: AudioContext | null = null;
    let grabador: MediaRecorder | null = null;
    let detencionGrabador: Promise<void> | null = null;
    let intervaloFragmento: number | undefined;
    let intervaloReloj: number | undefined;
    let bloqueoPantalla: WakeLockSentinel | null = null;
    let ultimoOrden = 0;
    let formato = '';
    let cola: FragmentoEnCola[] = [];
    let procesandoCola: Promise<void> | null = null;
    // Segundos grabados antes del tramo actual y momento en que empezó el tramo.
    let segundosPrevios = 0;
    let inicioTramo = 0;
    // Qué hacer al presionar "Reintentar" si falló una subida.
    let accionPendiente: 'pausar' | 'terminar' = 'terminar';

    if (abierta) {
        reunion.value = abierta;
        ultimoOrden = abierta.fragmentos_subidos ?? 0;
        segundosPrevios = abierta.segundos_grabados ?? 0;
        estado.value = 'pausado';
    }

    // Tiempo grabado, sin contar las pausas.
    const segundos = computed(() => {
        const tramo =
            estado.value === 'escuchando'
                ? Math.max(0, Math.floor((ahora.value - inicioTramo) / 1000))
                : 0;

        return segundosPrevios + tramo;
    });

    const hayTrabajoPendiente = computed(
        () =>
            ESTADOS_OCUPADOS.includes(estado.value) ||
            subidas.value.some((subida) => subida.estado !== 'subido'),
    );

    function actualizarSubida(orden: number, cambios: Partial<Subida>) {
        subidas.value = subidas.value.map((subida) =>
            subida.orden === orden ? { ...subida, ...cambios } : subida,
        );
    }

    function nuevoGrabador(): MediaRecorder {
        const orden = ++ultimoOrden;
        const inicio = performance.now();
        const partes: Blob[] = [];
        const recorder = new MediaRecorder(stream!, {
            mimeType: formato,
            audioBitsPerSecond: config.audioBitsPorSegundo,
        });

        recorder.ondataavailable = (evento) => {
            if (evento.data.size > 0) {
                partes.push(evento.data);
            }
        };

        detencionGrabador = new Promise<void>((resolve) => {
            recorder.onstop = () => {
                const blob = new Blob(partes, { type: recorder.mimeType });

                if (blob.size > 0) {
                    encolar({
                        orden,
                        blob,
                        duracion: Math.round(
                            (performance.now() - inicio) / 1000,
                        ),
                        tamano: blob.size,
                        estado: 'pendiente',
                        intentos: 0,
                    });
                }

                resolve();
            };
        });

        recorder.start();

        return recorder;
    }

    /**
     * Cierra el fragmento actual y abre el siguiente. El nuevo grabador arranca
     * antes de detener el anterior para no perder audio entre fragmentos.
     */
    function rotarFragmento() {
        const anterior = grabador;

        grabador = nuevoGrabador();
        anterior?.stop();
    }

    function encolar(fragmento: FragmentoEnCola) {
        const { orden, duracion, tamano, intentos } = fragmento;

        cola.push(fragmento);
        subidas.value = [
            ...subidas.value,
            { orden, duracion, tamano, intentos, estado: fragmento.estado },
        ];
        void procesarCola();
    }

    async function subir(fragmento: FragmentoEnCola): Promise<boolean> {
        const datos = new FormData();
        const extension = fragmento.blob.type.includes('mp4')
            ? 'm4a'
            : fragmento.blob.type.includes('ogg')
              ? 'ogg'
              : 'webm';

        datos.append(
            'audio',
            fragmento.blob,
            `${String(fragmento.orden).padStart(4, '0')}.${extension}`,
        );
        datos.append('orden', String(fragmento.orden));
        datos.append('duracion_segundos', String(fragmento.duracion));

        for (let intento = 1; intento <= MAX_INTENTOS; intento++) {
            actualizarSubida(fragmento.orden, {
                estado: 'subiendo',
                intentos: intento,
            });

            try {
                await post(subirFragmento.url(reunion.value!.id), datos);
                actualizarSubida(fragmento.orden, {
                    estado: 'subido',
                    error: undefined,
                });

                return true;
            } catch (e) {
                const respuesta = respuestaDeError(e);
                const mensaje =
                    (respuesta &&
                        leerJson<{ message?: string }>(respuesta)?.message) ||
                    'Sin conexión con el servidor.';

                // 409 y 422 no se arreglan reintentando.
                const definitivo =
                    respuesta?.status === 409 || respuesta?.status === 422;

                if (definitivo || intento === MAX_INTENTOS) {
                    actualizarSubida(fragmento.orden, {
                        estado: 'error',
                        error: mensaje,
                    });

                    return false;
                }

                await esperar(ESPERA_REINTENTO_MS[intento - 1]);
            }
        }

        return false;
    }

    /**
     * Sube los fragmentos en orden. Si uno falla se detiene, para que el
     * servidor nunca reciba el fragmento 5 sin el 4.
     */
    function procesarCola(): Promise<void> {
        procesandoCola ??= (async () => {
            while (cola.length > 0) {
                const subido = await subir(cola[0]);

                if (!subido) {
                    break;
                }

                cola.shift();
            }
        })().finally(() => {
            procesandoCola = null;
        });

        return procesandoCola;
    }

    async function prepararMicrofono() {
        formato =
            FORMATOS.find((tipo) => MediaRecorder.isTypeSupported(tipo)) ?? '';

        stream = await navigator.mediaDevices.getUserMedia({
            audio: {
                channelCount: 1,
                echoCancellation: false,
                noiseSuppression: true,
                autoGainControl: true,
            },
        });

        microfono.value = stream;
        contextoAudio = new AudioContext();
        const nodo = contextoAudio.createAnalyser();
        nodo.fftSize = 256;
        nodo.smoothingTimeConstant = 0.8;
        contextoAudio.createMediaStreamSource(stream).connect(nodo);
        analizador.value = nodo;
    }

    async function mantenerPantallaEncendida() {
        try {
            bloqueoPantalla = await navigator.wakeLock?.request('screen');
        } catch {
            // No es crítico: sin wake lock solo hay que cuidar la suspensión a mano.
        }
    }

    function liberarMicrofono() {
        window.clearInterval(intervaloFragmento);
        window.clearInterval(intervaloReloj);
        stream?.getTracks().forEach((pista) => pista.stop());
        void contextoAudio?.close();
        void bloqueoPantalla?.release();
        stream = null;
        contextoAudio = null;
        bloqueoPantalla = null;
        analizador.value = null;
        microfono.value = null;
    }

    function mensajeDeError(e: unknown, porDefecto: string): string {
        const respuesta = respuestaDeError(e);

        return (
            (respuesta && leerJson<{ message?: string }>(respuesta)?.message) ||
            porDefecto
        );
    }

    async function empezarTramo() {
        grabador = nuevoGrabador();
        intervaloFragmento = window.setInterval(
            rotarFragmento,
            config.fragmentoSegundos * 1000,
        );
        intervaloReloj = window.setInterval(
            () => (ahora.value = Date.now()),
            1000,
        );
        inicioTramo = Date.now();
        ahora.value = inicioTramo;
        await mantenerPantallaEncendida();
        estado.value = 'escuchando';
    }

    /**
     * Cierra el fragmento en curso y suelta el micrófono.
     */
    async function detenerTramo() {
        window.clearInterval(intervaloFragmento);
        grabador?.stop();
        await detencionGrabador;
        grabador = null;
        segundosPrevios += Math.floor((Date.now() - inicioTramo) / 1000);
        liberarMicrofono();
    }

    /**
     * Botón "Empezar reunión": crea una reunión nueva y empieza a grabar.
     */
    async function escuchar(titulo?: string) {
        if (estado.value !== 'inactivo') {
            return;
        }

        error.value = null;
        estado.value = 'iniciando';

        try {
            await prepararMicrofono();
        } catch {
            liberarMicrofono();
            estado.value = 'inactivo';
            error.value =
                'No se pudo acceder al micrófono. Revisa que el navegador tenga permiso.';

            return;
        }

        try {
            const respuesta = await post<{ reunion: Reunion }>(
                crearReunion.url(),
                { titulo: titulo || null },
            );
            reunion.value = respuesta.reunion;
        } catch (e) {
            liberarMicrofono();
            estado.value = 'inactivo';
            error.value = mensajeDeError(e, 'No se pudo iniciar la reunión.');

            return;
        }

        ultimoOrden = 0;
        segundosPrevios = 0;
        subidas.value = [];
        cola = [];
        await empezarTramo();
    }

    /**
     * Botón "Pausar": deja de grabar pero la reunión sigue abierta.
     */
    async function pausar() {
        if (estado.value !== 'escuchando') {
            return;
        }

        estado.value = 'pausando';
        await detenerTramo();
        await confirmarPausa();
    }

    async function confirmarPausa() {
        accionPendiente = 'pausar';
        await procesarCola();

        if (cola.length > 0) {
            estado.value = 'subida-pendiente';
            error.value =
                'Hay fragmentos sin subir. Revisa que el servidor esté encendido y presiona "Reintentar".';

            return;
        }

        try {
            const respuesta = await post<{ reunion: Reunion }>(
                pausarReunion.url(reunion.value!.id),
            );
            reunion.value = respuesta.reunion;
            segundosPrevios =
                respuesta.reunion.segundos_grabados ?? segundosPrevios;
            estado.value = 'pausado';
            error.value = null;
        } catch (e) {
            estado.value = 'subida-pendiente';
            error.value = mensajeDeError(
                e,
                'No se pudo pausar la reunión. Presiona "Reintentar".',
            );
        }
    }

    /**
     * Botón "Reanudar": vuelve a grabar en la misma reunión.
     */
    async function reanudar() {
        if (estado.value !== 'pausado' || !reunion.value) {
            return;
        }

        error.value = null;
        estado.value = 'reanudando';

        try {
            await prepararMicrofono();
        } catch {
            liberarMicrofono();
            estado.value = 'pausado';
            error.value =
                'No se pudo acceder al micrófono. Revisa que el navegador tenga permiso.';

            return;
        }

        try {
            const respuesta = await post<{ reunion: Reunion }>(
                reanudarReunion.url(reunion.value.id),
            );
            reunion.value = respuesta.reunion;
            ultimoOrden = Math.max(
                ultimoOrden,
                respuesta.reunion.fragmentos_subidos ?? 0,
            );
            segundosPrevios =
                respuesta.reunion.segundos_grabados ?? segundosPrevios;
        } catch (e) {
            liberarMicrofono();
            estado.value = 'pausado';
            error.value = mensajeDeError(e, 'No se pudo reanudar la reunión.');

            return;
        }

        await empezarTramo();
    }

    async function cerrarReunionEnServidor() {
        accionPendiente = 'terminar';
        estado.value = 'terminando';

        await procesarCola();

        if (cola.length > 0) {
            estado.value = 'subida-pendiente';
            error.value =
                'Hay fragmentos sin subir. Revisa que el servidor esté encendido y presiona "Reintentar".';

            return;
        }

        try {
            const respuesta = await post<{
                descartada: boolean;
                reunion?: Reunion;
            }>(terminarReunion.url(reunion.value!.id));

            reunion.value = respuesta.descartada
                ? null
                : (respuesta.reunion ?? null);
            estado.value = 'inactivo';
            error.value = null;
            segundosPrevios = 0;
            subidas.value = [];
        } catch (e) {
            estado.value = 'subida-pendiente';
            error.value = mensajeDeError(
                e,
                'No se pudo cerrar la reunión en el servidor. Presiona "Reintentar".',
            );
        }
    }

    /**
     * Botón "Terminar reunión": funciona grabando o en pausa. Cierra el último
     * fragmento, espera a que todos suban y recién entonces cierra la reunión.
     */
    async function terminar() {
        if (estado.value === 'escuchando') {
            estado.value = 'terminando';
            await detenerTramo();
        } else if (estado.value !== 'pausado') {
            return;
        }

        await cerrarReunionEnServidor();
    }

    async function reintentar() {
        if (estado.value !== 'subida-pendiente') {
            return;
        }

        error.value = null;

        if (accionPendiente === 'pausar') {
            estado.value = 'pausando';
            await confirmarPausa();
        } else {
            await cerrarReunionEnServidor();
        }
    }

    function avisarAntesDeSalir(evento: BeforeUnloadEvent) {
        if (hayTrabajoPendiente.value) {
            evento.preventDefault();
        }
    }

    let quitarAvisoInertia: (() => void) | undefined;

    onMounted(() => {
        window.addEventListener('beforeunload', avisarAntesDeSalir);
        quitarAvisoInertia = router.on('before', (evento) => {
            if (
                hayTrabajoPendiente.value &&
                !window.confirm(
                    'Jarvis está grabando o subiendo audio. Si sales de esta página se perderá el último tramo. ¿Salir de todas formas?',
                )
            ) {
                evento.preventDefault();
            }
        });
    });

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', avisarAntesDeSalir);
        quitarAvisoInertia?.();
        window.clearInterval(intervaloFragmento);
        grabador?.stop();
        liberarMicrofono();
    });

    return {
        estado,
        reunion,
        subidas,
        error,
        analizador,
        microfono,
        segundos,
        escuchar,
        pausar,
        reanudar,
        terminar,
        reintentar,
    };
}
