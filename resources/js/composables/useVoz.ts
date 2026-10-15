import { onBeforeUnmount, ref } from 'vue';
import VozController from '@/actions/App/Http/Controllers/VozController';
import { tokenXsrf } from '@/lib/xsrf';

const PREFERENCIA = 'jarvis.voz-automatica';
const PREFERENCIA_VOZ = 'jarvis.voz';
const MUESTRA = 'Hola, soy tu Asistente IA. Así sonarán mis respuestas.';

function leer(clave: string): string | null {
    try {
        return localStorage.getItem(clave);
    } catch {
        return null;
    }
}

function guardar(clave: string, valor: string) {
    try {
        localStorage.setItem(clave, valor);
    } catch {
        // Sin almacenamiento la preferencia solo dura mientras la página esté abierta.
    }
}

/**
 * El asistente lee en voz alta sus respuestas con la voz de OpenAI que elija
 * el usuario. El audio de cada texto se guarda mientras la página está
 * abierta, así repetirlo no vuelve a cobrarse.
 */
export function useVoz(vozPorDefecto: string, vocesDisponibles: string[]) {
    // Respuesta que se está preparando o sonando (su clave en el chat).
    const activa = ref<string | null>(null);
    const cargando = ref(false);
    const error = ref<string | null>(null);
    const automatica = ref(leer(PREFERENCIA) === '1');
    const guardada = leer(PREFERENCIA_VOZ);
    const voz = ref(
        guardada && vocesDisponibles.includes(guardada)
            ? guardada
            : vozPorDefecto,
    );

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
            body: JSON.stringify({ texto, voz: voz.value }),
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
            // El mismo texto con otra voz es otro audio.
            const claveAudio = `${voz.value}|${texto}`;
            let url = audios.get(claveAudio);

            if (!url) {
                url = await generar(texto);
                audios.set(claveAudio, url);
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

        guardar(PREFERENCIA, automatica.value ? '1' : '0');
    }

    /**
     * Cambia la voz y la hace escuchar con una frase de muestra.
     */
    function elegirVoz(nueva: string) {
        voz.value = nueva;
        guardar(PREFERENCIA_VOZ, nueva);
        void reproducir('muestra', MUESTRA);
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
        voz,
        elegirVoz,
        reproducir,
        alternar,
        detener,
        alternarAutomatica,
    };
}
