<script setup lang="ts">
import { Head, http, Link } from '@inertiajs/vue3';
import {
    CircleAlert,
    CircleCheck,
    FileText,
    ListTodo,
    LoaderCircle,
    MessageSquare,
    Mic,
    Pause,
    Play,
    Radar,
    RotateCw,
    Square,
    X,
} from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { show as verReunion } from '@/actions/App/Http/Controllers/HistorialController';
import { agenda as actualizarAgenda } from '@/actions/App/Http/Controllers/ReunionController';
import ChatJarvis from '@/components/jarvis/ChatJarvis.vue';
import LineaDeTiempoReunion from '@/components/jarvis/LineaDeTiempoReunion.vue';
import PreparacionReunion from '@/components/jarvis/PreparacionReunion.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useGrabadora } from '@/composables/useGrabadora';
import { useLineaDeTiempo } from '@/composables/useLineaDeTiempo';
import { useOrdenesDeVoz } from '@/composables/useOrdenesDeVoz';
import { useSeguimientoReunion } from '@/composables/useSeguimientoReunion';
import { formatearHora, formatearTiempo } from '@/lib/formato';
import type { OrdenVoz } from '@/lib/ordenesVoz';
import { jarvis } from '@/routes';
import type {
    ConfigDocumentos,
    ConfigGrabacion,
    ConfigVoces,
    EstadoFragmento,
    Reunion,
} from '@/types';

const props = defineProps<{
    reunionActiva: Reunion | null;
    ultimaReunion: Reunion | null;
    grabacion: ConfigGrabacion;
    documentos: ConfigDocumentos;
    dictadoMaxSegundos: number;
    voces: ConfigVoces;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Asistente IA', href: jarvis() }],
        // La página tiene su propia barra (vista, reunión y botón del menú).
        sinEncabezado: true,
    },
});

const titulo = ref('');

// Vista del asistente: la conversación (núcleo y último intercambio) o el
// chat completo. Es la misma conversación; se recuerda en este navegador.
type Vista = 'conversacion' | 'chat';
const CLAVE_VISTA = 'asistente-ia.vista';

function leerVista(): Vista {
    try {
        return localStorage.getItem(CLAVE_VISTA) === 'chat'
            ? 'chat'
            : 'conversacion';
    } catch {
        return 'conversacion';
    }
}

const vista = ref<Vista>(leerVista());

watch(vista, (actual) => {
    try {
        localStorage.setItem(CLAVE_VISTA, actual);
    } catch {
        // Sin almacenamiento, la vista elegida dura mientras la página siga abierta.
    }
});

const opcionesVista = [
    { valor: 'conversacion', texto: 'Conversación', icono: Radar },
    { valor: 'chat', texto: 'Chat', icono: MessageSquare },
] as const;

// Panel de la reunión (título, agenda, minuto a minuto y acta): se abre a pedido.
const panelAbierto = ref(false);
const cerrarPanelBoton = ref<HTMLButtonElement | null>(null);
let focoAntesDelPanel: HTMLElement | null = null;

async function abrirPanel() {
    focoAntesDelPanel = document.activeElement as HTMLElement | null;
    panelAbierto.value = true;
    await nextTick();
    cerrarPanelBoton.value?.focus();
}

function cerrarPanel() {
    panelAbierto.value = false;
    focoAntesDelPanel?.focus();
}

useEventListener(window, 'keydown', (evento: KeyboardEvent) => {
    if (evento.key === 'Escape' && panelAbierto.value) {
        cerrarPanel();
    }
});

const {
    estado,
    reunion,
    subidas,
    error,
    analizador,
    segundos,
    escuchar,
    pausar,
    reanudar,
    terminar,
    reintentar,
} = useGrabadora(props.grabacion, props.reunionActiva);

// Reunión terminada cuyo avance y resumen se muestran en el panel lateral.
const reunionMostrada = ref<Reunion | null>(props.ultimaReunion);

watch(reunion, (actual) => {
    reunionMostrada.value =
        actual && !['grabando', 'pausada'].includes(actual.estado)
            ? actual
            : null;
});

const { fragmentos, resumen, reprocesando, reprocesar } =
    useSeguimientoReunion(reunionMostrada);

const escuchando = computed(() => estado.value === 'escuchando');
const reunionAbierta = computed(
    () => !['inactivo', 'iniciando'].includes(estado.value),
);

// Reunión a la que pertenece el chat: la abierta y, al terminarla, la misma
// (ahí aparece el acta) hasta empezar otra o pulsar "Nueva" en el chat.
const reunionDelChat = ref<string | null>(props.reunionActiva?.id ?? null);

watch(
    () => (reunionAbierta.value ? reunion.value?.id : undefined),
    (id) => {
        if (id) {
            reunionDelChat.value = id;
        }
    },
    { immediate: true },
);

const {
    tramos,
    acta,
    reunion: reunionChat,
    actualizar: actualizarChat,
} = useLineaDeTiempo(reunionDelChat);

// Al terminar se consulta enseguida para mostrar en el chat que se genera el acta.
watch(reunionAbierta, (abierta, antes) => {
    if (antes && !abierta) {
        actualizarChat();
    }
});

const subidasPendientes = computed(() =>
    subidas.value.filter((subida) => subida.estado !== 'subido'),
);

// Si la reunión lleva mucho en pausa, probablemente ya es otra reunión.
const LIMITE_PAUSA_MS = 2 * 60 * 60 * 1000;
const ahora = ref(Date.now());
const reloj = window.setInterval(() => (ahora.value = Date.now()), 60_000);
onBeforeUnmount(() => window.clearInterval(reloj));

const pausaLarga = computed(() => {
    if (estado.value !== 'pausado' || !reunion.value) {
        return false;
    }

    // Una reunión que quedó grabando al cerrar el navegador no tiene hora de pausa.
    const desde = new Date(
        reunion.value.pausada_en ?? reunion.value.iniciada_en,
    ).getTime();

    return ahora.value - desde > LIMITE_PAUSA_MS;
});
const transcritos = computed(
    () => fragmentos.value.filter((f) => f.estado === 'listo').length,
);

const textoEstado = computed(
    () =>
        ({
            inactivo: 'En espera',
            iniciando: 'Iniciando',
            escuchando: 'Escuchando',
            pausando: 'Pausando',
            pausado: 'En pausa',
            reanudando: 'Reanudando',
            terminando: 'Guardando',
            'subida-pendiente': 'Subida pendiente',
        })[estado.value],
);

const textoFragmento: Record<EstadoFragmento, string> = {
    pendiente: 'en cola',
    transcribiendo: 'transcribiendo',
    listo: 'listo',
    error: 'error',
};

const colorFragmento: Record<EstadoFragmento, string> = {
    pendiente: 'text-hud-suave',
    transcribiendo: 'text-hud-acento-texto animate-pulse',
    listo: 'text-emerald-600',
    error: 'text-rose-600',
};

// Agenda de la próxima reunión: queda como borrador en el navegador hasta empezar.
const CLAVE_BORRADOR_AGENDA = 'asistente-ia.agenda-borrador';

function leerBorradorAgenda(): string[] {
    try {
        const guardado = JSON.parse(
            localStorage.getItem(CLAVE_BORRADOR_AGENDA) ?? '[]',
        ) as unknown;

        return Array.isArray(guardado)
            ? guardado.filter((punto) => typeof punto === 'string')
            : [];
    } catch {
        return [];
    }
}

const agendaBorrador = ref<string[]>(leerBorradorAgenda());

watch(agendaBorrador, (puntos) => {
    try {
        localStorage.setItem(CLAVE_BORRADOR_AGENDA, JSON.stringify(puntos));
    } catch {
        // Sin almacenamiento, el borrador solo dura mientras la página siga abierta.
    }
});

// Con la reunión en curso, la agenda es la de la reunión y se guarda al cambiarla.
const agenda = computed<string[]>({
    get: () =>
        reunionAbierta.value && reunion.value
            ? (reunion.value.agenda ?? [])
            : agendaBorrador.value,
    set: (puntos) => {
        if (reunionAbierta.value && reunion.value) {
            void guardarAgenda(puntos);
        } else {
            agendaBorrador.value = puntos;
        }
    },
});

async function guardarAgenda(puntos: string[]) {
    const actual = reunion.value;

    if (!actual) {
        return;
    }

    const anterior = actual.agenda;
    reunion.value = { ...actual, agenda: puntos };

    try {
        await http.getClient().request({
            method: 'put',
            url: actualizarAgenda.url(actual.id),
            data: { agenda: puntos },
            headers: { Accept: 'application/json' },
        });
    } catch {
        if (reunion.value?.id === actual.id) {
            reunion.value = { ...reunion.value, agenda: anterior };
        }

        toast.error('No se pudo guardar la agenda.');
    }
}

async function iniciar() {
    await escuchar(titulo.value.trim(), agendaBorrador.value);

    if (estado.value === 'escuchando') {
        titulo.value = '';
        agendaBorrador.value = [];
    }
}

function confirmarTerminar() {
    if (
        window.confirm(
            '¿Terminar la reunión? Ya no se podrá agregar más audio y el Asistente IA generará el acta.',
        )
    ) {
        void terminar();
    }
}

// Órdenes de voz: "Jarvis, pausa / reanuda / termina la reunión" o una pregunta para el chat.
const chat = ref<InstanceType<typeof ChatJarvis> | null>(null);

function ejecutarOrden(orden: OrdenVoz) {
    switch (orden.tipo) {
        case 'pausar':
            if (estado.value === 'escuchando') {
                toast('Asistente IA: pausando la reunión.');
                void pausar();
            }

            break;
        case 'reanudar':
            if (estado.value === 'pausado') {
                toast('Asistente IA: reanudando la reunión.');
                void reanudar();
            }

            break;
        case 'terminar':
            if (estado.value === 'escuchando' || estado.value === 'pausado') {
                confirmarTerminar();
            }

            break;
        case 'pregunta':
            void chat.value?.proponer(orden.texto);
            toast(
                'Asistente IA: tu pregunta está en el chat. Pulsa Enter para enviarla.',
            );
            break;
    }
}

const ordenesVoz = useOrdenesDeVoz(ejecutarOrden);

// Conversación por voz en vivo desde el chat: mientras se habla con la voz, la
// reunión se pausa (para que su voz no se grabe) y se reanuda al terminar o al
// silenciar la voz, que queda abierta en espera.
const conversandoEnVivo = ref(false);
let reanudarAlTerminarEnVivo = false;

// Se pausa antes de abrir el micrófono de la voz: si la grabación sigue con el
// micrófono abierto (sin cancelación de eco), el navegador puede quitársela
// también a la voz, y el asistente se escucharía a sí mismo.
async function pausarParaEnVivo() {
    if (estado.value === 'escuchando') {
        reanudarAlTerminarEnVivo = true;
        toast('Reunión en pausa mientras conversas con el Asistente IA.');
        await pausar();
    }
}

async function reanudarEnSilencio() {
    if (reanudarAlTerminarEnVivo && estado.value === 'pausado') {
        reanudarAlTerminarEnVivo = false;
        toast('Voz en espera: la reunión sigue grabando.');
        await reanudar();
    }
}

async function alCambiarEnVivo(activo: boolean) {
    conversandoEnVivo.value = activo;

    if (!activo && reanudarAlTerminarEnVivo) {
        reanudarAlTerminarEnVivo = false;

        if (estado.value === 'pausado') {
            toast('Reanudando la reunión.');
            await reanudar();
        }
    }
}

// En pausa se sigue escuchando (para "Jarvis, reanuda") solo un tiempo: así
// una reunión olvidada en pausa no deja el micrófono abierto y cobrando.
const LIMITE_ESCUCHA_EN_PAUSA_MS = 30 * 60 * 1000;

const escuchaEnReposo = computed(() => {
    if (estado.value !== 'pausado' || !reunion.value) {
        return false;
    }

    const desde = new Date(
        reunion.value.pausada_en ?? reunion.value.iniciada_en,
    ).getTime();

    return ahora.value - desde > LIMITE_ESCUCHA_EN_PAUSA_MS;
});

const reunionQueEscucha = computed(() => {
    const abierta = [
        'escuchando',
        'pausando',
        'pausado',
        'reanudando',
    ].includes(estado.value);

    // Durante la conversación en vivo no se escuchan órdenes: ya hay una escucha.
    return props.grabacion.enVivo &&
        abierta &&
        reunion.value &&
        !escuchaEnReposo.value &&
        !conversandoEnVivo.value
        ? reunion.value.id
        : null;
});

watch(
    reunionQueEscucha,
    (id) => {
        if (id) {
            void ordenesVoz.activar(id);
        } else {
            ordenesVoz.desactivar();
        }
    },
    { immediate: true },
);
</script>

<template>
    <Head title="Inicio" />

    <div
        class="fondo-nucleo flex h-svh min-h-0 flex-col bg-hud-fondo text-hud-texto md:h-[calc(100svh-1rem)] md:rounded-xl"
    >
        <header
            class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b px-4 py-3 transition-colors"
            :class="
                vista === 'chat' ? 'border-hud-acento/20' : 'border-transparent'
            "
        >
            <SidebarTrigger class="-ml-1 text-hud-suave" />
            <h1 class="hidden text-sm font-semibold sm:block">Asistente IA</h1>

            <div
                class="inline-flex rounded-full border border-hud-acento/20 bg-hud-superficie/50 p-0.5"
                role="group"
                aria-label="Vista"
            >
                <button
                    v-for="opcion in opcionesVista"
                    :key="opcion.valor"
                    type="button"
                    class="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs transition-colors"
                    :class="
                        vista === opcion.valor
                            ? 'bg-hud-fondo font-medium text-hud-acento-texto shadow-sm'
                            : 'text-hud-suave hover:text-hud-texto'
                    "
                    :aria-pressed="vista === opcion.valor"
                    :title="
                        opcion.valor === 'chat'
                            ? 'Ver todo el chat escrito'
                            : 'Ver solo la conversación'
                    "
                    @click="vista = opcion.valor"
                >
                    <component :is="opcion.icono" class="size-3.5" />
                    <span class="hidden sm:inline">{{ opcion.texto }}</span>
                </button>
            </div>

            <div class="flex-1" />

            <Button
                v-if="!reunionAbierta"
                :disabled="estado === 'iniciando'"
                class="rounded-full bg-hud-acento text-hud-sobre-acento hover:bg-hud-acento-fuerte"
                @click="iniciar"
            >
                <LoaderCircle
                    v-if="estado === 'iniciando'"
                    class="animate-spin"
                />
                <Mic v-else />
                Empezar reunión
            </Button>

            <div
                v-else
                class="flex items-center gap-2 rounded-full border border-hud-acento/20 py-1 pr-1 pl-3 text-sm"
            >
                <span
                    class="size-2 shrink-0 rounded-full"
                    :class="
                        escuchando
                            ? 'animate-pulse bg-rose-500 motion-reduce:animate-none'
                            : estado === 'subida-pendiente'
                              ? 'bg-rose-500'
                              : 'bg-amber-400'
                    "
                    aria-hidden="true"
                />
                <span
                    class="hidden text-hud-suave sm:inline"
                    aria-live="polite"
                >
                    {{ textoEstado }}
                </span>
                <span class="font-mono tabular-nums">
                    {{ formatearTiempo(segundos) }}
                </span>
                <button
                    v-if="estado === 'escuchando' || estado === 'pausando'"
                    type="button"
                    class="flex size-8 items-center justify-center rounded-full text-hud-texto hover:bg-hud-superficie disabled:opacity-50"
                    :disabled="estado === 'pausando'"
                    aria-label="Pausar"
                    title="Pausar"
                    @click="pausar"
                >
                    <Pause class="size-4" />
                </button>
                <button
                    v-else-if="estado === 'pausado' || estado === 'reanudando'"
                    type="button"
                    class="flex size-8 items-center justify-center rounded-full text-hud-acento-texto hover:bg-hud-superficie disabled:opacity-50"
                    :disabled="estado === 'reanudando'"
                    aria-label="Reanudar"
                    title="Reanudar"
                    @click="reanudar"
                >
                    <Play class="size-4" />
                </button>
                <button
                    v-else-if="estado === 'subida-pendiente'"
                    type="button"
                    class="flex size-8 items-center justify-center rounded-full text-hud-acento-texto hover:bg-hud-superficie"
                    aria-label="Reintentar la subida del audio"
                    title="Reintentar la subida del audio"
                    @click="reintentar"
                >
                    <RotateCw class="size-4" />
                </button>
                <button
                    v-if="estado !== 'subida-pendiente'"
                    type="button"
                    class="flex size-8 items-center justify-center rounded-full text-rose-600 hover:bg-rose-50 disabled:opacity-50"
                    :disabled="estado !== 'escuchando' && estado !== 'pausado'"
                    aria-label="Terminar reunión"
                    title="Terminar reunión"
                    @click="confirmarTerminar"
                >
                    <Square class="size-4" />
                </button>
            </div>

            <Button
                variant="outline"
                class="rounded-full border-hud-acento/20 bg-transparent text-hud-texto hover:bg-hud-superficie"
                aria-label="Reunión: título, agenda y minuto a minuto"
                title="Título, agenda y minuto a minuto"
                @click="abrirPanel"
            >
                <ListTodo />
                <span class="hidden sm:inline">Reunión</span>
            </Button>
        </header>

        <div
            v-if="pausaLarga || error || reunionQueEscucha || escuchaEnReposo"
            class="flex flex-col items-center gap-2 px-4 pt-2 text-center"
        >
            <p
                v-if="pausaLarga && reunion"
                class="max-w-xl rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-700"
            >
                Esta reunión está en pausa desde las
                {{ formatearHora(reunion.pausada_en ?? reunion.iniciada_en) }}.
                Si ahora empieza una reunión distinta, primero
                <strong>termínala</strong> y luego presiona
                <strong>Empezar reunión</strong>.
            </p>
            <p
                v-if="error"
                class="flex items-center gap-2 text-sm text-rose-600"
                role="alert"
            >
                <CircleAlert class="size-4 shrink-0" />
                {{ error }}
            </p>
            <p
                v-if="reunionQueEscucha"
                class="flex items-center gap-2 text-xs text-hud-suave"
                :title="
                    ordenesVoz.aviso.value ??
                    'Ejemplos: «Asistente, pausa la reunión», «Asistente, reanuda», «Asistente, termina la reunión» o «Asistente, busca en la web…»'
                "
            >
                <span
                    class="size-1.5 shrink-0 rounded-full"
                    :class="
                        ordenesVoz.estado.value === 'activo'
                            ? 'bg-emerald-400'
                            : ordenesVoz.estado.value === 'error' ||
                                ordenesVoz.aviso.value
                              ? 'bg-rose-400'
                              : 'animate-pulse bg-amber-400'
                    "
                />
                <template v-if="ordenesVoz.aviso.value">
                    {{ ordenesVoz.aviso.value }}
                </template>
                <template v-else-if="ordenesVoz.estado.value === 'activo'">
                    Di «Asistente, …» para pausar, reanudar, terminar o
                    preguntar
                </template>
                <template v-else>Activando órdenes de voz…</template>
            </p>
            <p v-else-if="escuchaEnReposo" class="text-xs text-hud-suave">
                Órdenes de voz en reposo por la pausa larga. Usa Reanudar.
            </p>
        </div>

        <div class="flex min-h-0 flex-1 flex-col px-4 pt-2 pb-4">
            <ChatJarvis
                ref="chat"
                :vista="vista"
                :analizador="analizador"
                :reunion-id="reunionDelChat"
                :reunion-abierta="
                    reunionAbierta && reunion?.id === reunionDelChat
                "
                :reunion="reunionChat"
                :acta="acta"
                :escuchando="escuchando"
                :documentos="documentos"
                :dictado-max-segundos="dictadoMaxSegundos"
                :voces="voces"
                :preparar-en-vivo="pausarParaEnVivo"
                :reanudar-en-silencio="reanudarEnSilencio"
                @salir-de-reunion="reunionDelChat = null"
                @en-vivo="alCambiarEnVivo"
                @ver-chat="vista = 'chat'"
            />
        </div>

        <!-- Panel de la reunión. Queda montado aunque esté cerrado: así no se
             pierde lo que se estaba preparando en la agenda. -->
        <div
            class="fixed inset-0 z-40 bg-black/20 transition-opacity motion-reduce:transition-none"
            :class="
                panelAbierto ? 'opacity-100' : 'pointer-events-none opacity-0'
            "
            aria-hidden="true"
            @click="cerrarPanel"
        />
        <aside
            class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col border-l border-hud-acento/20 bg-hud-fondo text-hud-texto shadow-xl transition-[transform,visibility] duration-300 motion-reduce:transition-none"
            :class="
                panelAbierto ? 'translate-x-0' : 'invisible translate-x-full'
            "
            role="dialog"
            :aria-modal="panelAbierto"
            aria-labelledby="titulo-panel-reunion"
        >
            <div
                class="flex items-center justify-between border-b border-hud-acento/20 px-5 py-4"
            >
                <h2 id="titulo-panel-reunion" class="text-sm font-semibold">
                    {{
                        reunionAbierta
                            ? estado === 'pausado'
                                ? 'Reunión en pausa'
                                : 'Reunión en curso'
                            : 'Reunión'
                    }}
                </h2>
                <button
                    ref="cerrarPanelBoton"
                    type="button"
                    class="flex size-8 items-center justify-center rounded-full text-hud-suave hover:bg-hud-superficie hover:text-hud-texto"
                    aria-label="Cerrar panel"
                    title="Cerrar (Esc)"
                    @click="cerrarPanel"
                >
                    <X class="size-4" />
                </button>
            </div>

            <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-5">
                <template v-if="!reunionAbierta">
                    <h3
                        class="font-mono text-xs tracking-[0.3em] text-hud-acento-texto/80 uppercase"
                    >
                        Próxima reunión
                    </h3>
                    <Input
                        v-model="titulo"
                        placeholder="Título de la reunión (opcional)"
                        aria-label="Título de la reunión (opcional)"
                        maxlength="255"
                        :disabled="estado !== 'inactivo'"
                        class="border-hud-acento/30 bg-hud-superficie/30 text-hud-texto placeholder:text-hud-suave/70"
                    />
                    <PreparacionReunion
                        v-model:agenda="agenda"
                        :en-curso="false"
                        :documentos="documentos"
                    />
                </template>

                <template v-if="reunion && reunionAbierta">
                    <dl class="grid grid-cols-2 gap-y-2 text-sm">
                        <dt class="text-hud-suave">Título</dt>
                        <dd class="truncate text-right">
                            {{ reunion.titulo || 'Sin título' }}
                        </dd>
                        <dt class="text-hud-suave">Inicio</dt>
                        <dd class="text-right">
                            {{ formatearHora(reunion.iniciada_en) }}
                        </dd>
                        <dt class="text-hud-suave">Grabado</dt>
                        <dd class="text-right tabular-nums">
                            {{ formatearTiempo(segundos) }}
                        </dd>
                    </dl>

                    <p
                        v-if="estado === 'pausado'"
                        class="text-sm text-hud-suave"
                    >
                        La reunión sigue abierta. Presiona
                        <strong class="text-hud-texto">Reanudar</strong>
                        para seguir grabando en ella o
                        <strong class="text-hud-texto">Terminar</strong>
                        para generar el acta.
                    </p>

                    <LineaDeTiempoReunion
                        :tramos="tramos"
                        :grabando="escuchando"
                    />

                    <PreparacionReunion
                        v-model:agenda="agenda"
                        :en-curso="true"
                        :documentos="documentos"
                    />

                    <p
                        v-if="subidasPendientes.length"
                        class="text-xs"
                        :class="
                            subidasPendientes.some((s) => s.estado === 'error')
                                ? 'text-rose-600'
                                : 'text-hud-suave'
                        "
                    >
                        {{
                            subidasPendientes.some((s) => s.estado === 'error')
                                ? 'No se pudo subir parte del audio.'
                                : 'Subiendo audio…'
                        }}
                    </p>
                </template>

                <template v-else-if="reunionMostrada">
                    <hr class="border-hud-acento/20" />
                    <h3
                        class="font-mono text-xs tracking-[0.3em] text-hud-acento-texto/80 uppercase"
                    >
                        Última reunión
                    </h3>
                    <dl class="grid grid-cols-2 gap-y-2 text-sm">
                        <dt class="text-hud-suave">Título</dt>
                        <dd class="truncate text-right">
                            {{ reunionMostrada.titulo || 'Sin título' }}
                        </dd>
                        <dt class="text-hud-suave">Inicio</dt>
                        <dd class="text-right">
                            {{ formatearHora(reunionMostrada.iniciada_en) }}
                        </dd>
                        <dt class="text-hud-suave">Duración</dt>
                        <dd class="text-right tabular-nums">
                            {{
                                formatearTiempo(
                                    reunionMostrada.duracion_segundos ?? 0,
                                )
                            }}
                        </dd>
                    </dl>

                    <div
                        v-if="reunionMostrada.estado === 'procesando'"
                        class="flex flex-col gap-3 rounded-lg border border-hud-acento/30 bg-hud-superficie/30 p-4 text-sm"
                        aria-live="polite"
                    >
                        <p class="flex items-center gap-2 font-medium">
                            <LoaderCircle class="size-4 animate-spin" />
                            <template
                                v-if="reunionMostrada.transcripcion_lista"
                            >
                                Generando el resumen…
                            </template>
                            <template v-else>
                                Transcribiendo {{ transcritos }} de
                                {{ fragmentos.length || '…' }}
                            </template>
                        </p>
                        <div
                            v-if="!reunionMostrada.transcripcion_lista"
                            class="h-1 overflow-hidden rounded bg-hud-superficie"
                        >
                            <div
                                class="h-full bg-hud-acento transition-all"
                                :style="{
                                    width: `${fragmentos.length ? (transcritos / fragmentos.length) * 100 : 0}%`,
                                }"
                            />
                        </div>
                    </div>

                    <div
                        v-else-if="reunionMostrada.estado === 'error'"
                        class="flex flex-col gap-3 rounded-lg border border-rose-300 bg-rose-50 p-4 text-sm"
                    >
                        <p
                            class="flex items-center gap-2 font-medium text-rose-600"
                        >
                            <CircleAlert class="size-4" />
                            {{
                                reunionMostrada.transcripcion_lista
                                    ? 'No se pudo generar el resumen'
                                    : 'Algunos fragmentos no se pudieron transcribir'
                            }}
                        </p>
                        <Button
                            size="sm"
                            :disabled="reprocesando"
                            class="self-start bg-hud-acento text-hud-sobre-acento hover:bg-hud-acento-fuerte"
                            @click="reprocesar"
                        >
                            <RotateCw />
                            Reintentar
                        </Button>
                    </div>

                    <p
                        v-else-if="reunionMostrada.estado === 'completada'"
                        class="flex items-center gap-2 text-sm font-medium text-emerald-600"
                    >
                        <CircleCheck class="size-4" />
                        Acta lista
                    </p>

                    <ul
                        v-if="
                            reunionMostrada.estado !== 'completada' &&
                            fragmentos.length
                        "
                        class="flex flex-col gap-1.5 text-sm"
                    >
                        <li
                            v-for="fragmento in fragmentos"
                            :key="fragmento.orden"
                            class="flex items-center justify-between gap-2 rounded border border-hud-acento/10 bg-hud-superficie/20 px-3 py-1.5"
                            :title="fragmento.error ?? undefined"
                        >
                            <span class="font-mono">
                                #{{ String(fragmento.orden).padStart(2, '0') }}
                            </span>
                            <span
                                class="text-xs"
                                :class="colorFragmento[fragmento.estado]"
                            >
                                {{ textoFragmento[fragmento.estado] }}
                            </span>
                        </li>
                    </ul>

                    <div
                        v-if="
                            resumen && reunionMostrada.estado === 'completada'
                        "
                        class="flex flex-col gap-3 text-sm"
                    >
                        <p class="leading-relaxed text-hud-texto/90">
                            {{ resumen.resumen_ejecutivo }}
                        </p>
                        <ul
                            v-if="resumen.temas_tratados.length"
                            class="flex flex-wrap gap-1.5"
                        >
                            <li
                                v-for="tema in resumen.temas_tratados"
                                :key="tema"
                                class="rounded-full border border-hud-acento/30 px-2.5 py-0.5 text-xs text-hud-texto/80"
                            >
                                {{ tema }}
                            </li>
                        </ul>
                        <p class="text-xs text-hud-suave">
                            {{ resumen.acuerdos.length }} acuerdo(s) ·
                            {{ resumen.pendientes.length }} pendiente(s)
                        </p>
                    </div>

                    <Button
                        v-if="reunionMostrada.estado !== 'procesando'"
                        size="sm"
                        variant="outline"
                        class="self-start border-hud-acento/40 bg-transparent text-hud-texto hover:bg-hud-superficie"
                        as-child
                    >
                        <Link :href="verReunion(reunionMostrada.id)">
                            <FileText />
                            Ver acta completa
                        </Link>
                    </Button>

                    <LineaDeTiempoReunion
                        v-if="
                            reunionDelChat === reunionMostrada.id &&
                            tramos.length
                        "
                        :tramos="tramos"
                        :grabando="false"
                    />
                </template>
            </div>
        </aside>
    </div>
</template>
