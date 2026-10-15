import { onBeforeUnmount, ref } from 'vue';
import DictadoController from '@/actions/App/Http/Controllers/DictadoController';
import { tokenXsrf } from '@/lib/xsrf';

export type EstadoDictado = 'inactivo' | 'grabando' | 'transcribiendo';

// Chrome y Edge graban WebM/Opus. Safari solo MP4.
const FORMATOS = [
    'audio/webm;codecs=opus',
    'audio/ogg;codecs=opus',
    'audio/mp4',
];

const EXTENSIONES: Record<string, string> = {
    webm: 'webm',
    ogg: 'ogg',
    mp4: 'mp4',
};

function mensajeMicrofono(e: unknown): string {
    const nombre = e instanceof Error ? e.name : '';

    if (nombre === 'NotAllowedError') {
        return 'El navegador no tiene permiso para usar el micrófono. Revísalo en el candado junto a la dirección.';
    }

    if (nombre === 'NotFoundError') {
        return 'No se encontró ningún micrófono. Revisa la entrada de audio en la configuración de sonido.';
    }

    return 'No se pudo usar el micrófono.';
}

/**
 * Dictado para el chat: graba lo que dice el usuario y lo devuelve como
 * texto (transcrito por OpenAI en el servidor).
 */
export function useDictado(maxSegundos: number) {
    const estado = ref<EstadoDictado>('inactivo');
    const segundos = ref(0);
    const error = ref<string | null>(null);

    let grabadora: MediaRecorder | null = null;
    let stream: MediaStream | null = null;
    let partes: Blob[] = [];
    let reloj: number | undefined;
    // Al cancelar se descarta lo grabado en vez de transcribirlo.
    let descartar = false;
    let alTerminar: ((texto: string) => void) | null = null;

    function soltarMicrofono() {
        window.clearInterval(reloj);
        stream?.getTracks().forEach((pista) => pista.stop());
        stream = null;
        grabadora = null;
    }

    async function transcribir(audio: Blob, tipo: string) {
        estado.value = 'transcribiendo';
        const extension =
            EXTENSIONES[tipo.split('/')[1]?.split(';')[0] ?? ''] ?? 'webm';
        const datos = new FormData();
        datos.append('audio', audio, `dictado.${extension}`);

        try {
            const respuesta = await fetch(DictadoController.url(), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': tokenXsrf(),
                },
                body: datos,
            });

            if (!respuesta.ok) {
                throw new Error(
                    respuesta.status === 429
                        ? 'Demasiados dictados seguidos. Espera un momento.'
                        : 'No se pudo transcribir el audio.',
                );
            }

            const { texto } = (await respuesta.json()) as { texto: string };

            if (texto) {
                alTerminar?.(texto);
            } else {
                error.value = 'No se entendió nada. Intenta hablar más cerca.';
            }
        } catch (e) {
            error.value =
                e instanceof Error ? e.message : 'No se pudo transcribir.';
        } finally {
            estado.value = 'inactivo';
        }
    }

    /**
     * Empieza a grabar. Al terminar, llama a `entregar` con el texto dictado.
     */
    async function iniciar(entregar: (texto: string) => void) {
        if (estado.value !== 'inactivo') {
            return;
        }

        error.value = null;
        alTerminar = entregar;

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: true, noiseSuppression: true },
            });
        } catch (e) {
            error.value = mensajeMicrofono(e);

            return;
        }

        const tipo =
            FORMATOS.find((formato) =>
                MediaRecorder.isTypeSupported(formato),
            ) ?? '';
        grabadora = new MediaRecorder(stream, {
            mimeType: tipo || undefined,
            audioBitsPerSecond: 32000,
        });
        partes = [];
        descartar = false;
        segundos.value = 0;

        grabadora.ondataavailable = (evento) => {
            if (evento.data.size) {
                partes.push(evento.data);
            }
        };

        grabadora.onstop = () => {
            const tipoFinal = grabadora?.mimeType || tipo || 'audio/webm';
            soltarMicrofono();

            if (descartar || !partes.length) {
                estado.value = 'inactivo';

                return;
            }

            void transcribir(new Blob(partes, { type: tipoFinal }), tipoFinal);
        };

        grabadora.start();
        estado.value = 'grabando';

        reloj = window.setInterval(() => {
            segundos.value++;

            // Corte de seguridad: un dictado no debería durar tanto.
            if (segundos.value >= maxSegundos) {
                terminar();
            }
        }, 1000);
    }

    /** Deja de grabar y transcribe lo dicho. */
    function terminar() {
        if (grabadora?.state === 'recording') {
            grabadora.stop();
        }
    }

    /** Deja de grabar sin transcribir. */
    function cancelar() {
        descartar = true;
        terminar();
    }

    onBeforeUnmount(() => {
        descartar = true;
        terminar();
        soltarMicrofono();
    });

    return { estado, segundos, error, iniciar, terminar, cancelar };
}
