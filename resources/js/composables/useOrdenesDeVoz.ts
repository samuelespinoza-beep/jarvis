import { computed, onBeforeUnmount, ref } from 'vue';
import { useTranscripcionEnVivo } from '@/composables/useTranscripcionEnVivo';
import { interpretarOrdenVoz } from '@/lib/ordenesVoz';
import type { OrdenVoz } from '@/lib/ordenesVoz';

/**
 * Escucha la reunión abierta (también en pausa) y avisa cuando alguien le da
 * una orden a Jarvis ("Jarvis, pausa la reunión"). Usa su propio micrófono,
 * así sigue oyendo aunque la grabación esté en pausa.
 */
export function useOrdenesDeVoz(alOrdenar: (orden: OrdenVoz) => void) {
    const transcripcion = useTranscripcionEnVivo({
        alCompletarFrase(frase) {
            const orden = interpretarOrdenVoz(frase);

            if (orden) {
                alOrdenar(orden);
            }
        },
    });

    const errorMicrofono = ref<string | null>(null);

    let stream: MediaStream | null = null;
    let reunionActual: string | null = null;
    let pedido = 0;

    function soltarMicrofono() {
        stream?.getTracks().forEach((pista) => pista.stop());
        stream = null;
    }

    async function activar(reunionId: string) {
        if (reunionActual === reunionId) {
            return;
        }

        desactivar();
        reunionActual = reunionId;
        const actual = ++pedido;

        try {
            const microfono = await navigator.mediaDevices.getUserMedia({
                audio: {
                    channelCount: 1,
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                },
            });

            // Mientras se pedía el micrófono, la reunión se cerró o cambió.
            if (actual !== pedido) {
                microfono.getTracks().forEach((pista) => pista.stop());

                return;
            }

            stream = microfono;
            errorMicrofono.value = null;
            await transcripcion.conectar(reunionId, microfono);
        } catch {
            if (actual === pedido) {
                errorMicrofono.value =
                    'Las órdenes de voz no pueden usar el micrófono. La reunión se sigue grabando normalmente.';
            }
        }
    }

    function desactivar() {
        pedido++;
        reunionActual = null;
        errorMicrofono.value = null;
        transcripcion.desconectar();
        soltarMicrofono();
    }

    onBeforeUnmount(desactivar);

    return {
        estado: transcripcion.estado,
        aviso: computed(
            () => errorMicrofono.value ?? transcripcion.mensaje.value,
        ),
        activar,
        desactivar,
    };
}
