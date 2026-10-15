import { onBeforeUnmount, ref } from 'vue';
import VozController from '@/actions/App/Http/Controllers/VozController';
import { tokenXsrf } from '@/lib/xsrf';

const PREFERENCIA = 'jarvis.voz-automatica';

function leerPreferencia(): boolean {
    try {
        return localStorage.getItem(PREFERENCIA) === '1';
    } catch {
        return false;
    }
}

/**
 * Jarvis lee en voz alta sus respuestas. El audio de cada texto se guarda
 * mientras la página está abierta, así repetirlo no vuelve a cobrarse.
 */
export function useVoz() {
    // Respuesta que se está preparando o sonando (su clave en el chat).
    const activa = ref<string | null>(null);
    const cargando = ref(false);
    const error = ref<string | null>(null);
    const automatica = ref(leerPreferencia());

    const audios = new Map<string, string>();
    let reproductor: HTMLAudioElement | null = null;
    let pedido = 0;

    function limpiar() {
        activa.value = null;
        cargando.value = false;
    }

    function detener() {
        pedido++;
        reproductor?.pause();
        reproductor = null;
        limpiar();
    }

    async function generar(texto: string): Promise<string> {
        const respuesta = await fetch(VozController.url(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'audio/mpeg, application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': tokenXsrf(),
            },
            body: JSON.stringify({ texto }),
        });

        if (!respuesta.ok) {
            throw new Error(
                respuesta.status === 429
                    ? 'Demasiadas lecturas seguidas. Espera un momento.'
                    : 'No se pudo generar el audio.',
            );
        }

        return URL.createObjectURL(await respuesta.blob());
    }

    async function reproducir(clave: string, texto: string) {
        detener();
        const actual = pedido;
        activa.value = clave;
        cargando.value = true;
        error.value = null;

        try {
            let url = audios.get(texto);

            if (!url) {
                url = await generar(texto);
                audios.set(texto, url);
            }

            // Mientras se generaba, el usuario pidió otra cosa o lo detuvo.
            if (actual !== pedido) {
                return;
            }

            reproductor = new Audio(url);
            reproductor.onended = () => actual === pedido && limpiar();
            await reproductor.play();
            cargando.value = false;
        } catch (e) {
            if (actual !== pedido) {
                return;
            }

            limpiar();
            error.value =
                e instanceof Error && e.name === 'NotAllowedError'
                    ? 'El navegador bloqueó el audio. Presiona el altavoz de la respuesta para escucharla.'
                    : e instanceof Error
                      ? e.message
                      : 'No se pudo reproducir el audio.';
        }
    }

    function alternar(clave: string, texto: string) {
        if (activa.value === clave) {
            detener();
        } else {
            void reproducir(clave, texto);
        }
    }

    function alternarAutomatica() {
        automatica.value = !automatica.value;

        if (!automatica.value) {
            detener();
        }

        try {
            localStorage.setItem(PREFERENCIA, automatica.value ? '1' : '0');
        } catch {
            // Sin almacenamiento la preferencia solo dura mientras la página esté abierta.
        }
    }

    onBeforeUnmount(() => {
        detener();
        audios.forEach((url) => URL.revokeObjectURL(url));
    });

    return {
        activa,
        cargando,
        error,
        automatica,
        reproducir,
        alternar,
        detener,
        alternarAutomatica,
    };
}
