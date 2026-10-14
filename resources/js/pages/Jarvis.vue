<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CircleAlert,
    CircleCheck,
    FileText,
    LoaderCircle,
    Mic,
    Pause,
    Play,
    RotateCw,
    Square,
} from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { show as verReunion } from '@/actions/App/Http/Controllers/HistorialController';
import ChatJarvis from '@/components/jarvis/ChatJarvis.vue';
import NucleoJarvis from '@/components/jarvis/NucleoJarvis.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useGrabadora } from '@/composables/useGrabadora';
import { useLineaDeTiempo } from '@/composables/useLineaDeTiempo';
import { useSeguimientoReunion } from '@/composables/useSeguimientoReunion';
import { useTranscripcionEnVivo } from '@/composables/useTranscripcionEnVivo';
import { formatearHora, formatearTiempo } from '@/lib/formato';
import { jarvis } from '@/routes';
import type {
    ConfigGrabacion,
    EstadoFragmento,
    Reunion,
    TramoReunion,
} from '@/types';

const props = defineProps<{
    reunionActiva: Reunion | null;
    ultimaReunion: Reunion | null;
    grabacion: ConfigGrabacion;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Jarvis', href: jarvis() }],
    },
});

const titulo = ref('');
const pestana = ref<'reunion' | 'chat'>('reunion');

const {
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
} = useGrabadora(props.grabacion, props.reunionActiva);

const enVivo = useTranscripcionEnVivo();
const cajaEnVivo = ref<HTMLElement | null>(null);

// La transcripción en vivo acompaña cada tramo grabado: empieza al escuchar o
// reanudar y se corta al pausar o terminar.
watch(
    () => [estado.value === 'escuchando', microfono.value] as const,
    ([grabando, stream]) => {
        if (props.grabacion.enVivo && grabando && stream && reunion.value) {
            void enVivo.conectar(reunion.value.id, stream);
        } else {
            enVivo.desconectar();
        }
    },
);

// Una reunión nueva empieza con el recuadro en vivo vacío.
watch(
    () => reunion.value?.id,
    (id, anterior) => {
        if (id && id !== anterior) {
            enVivo.limpiar();
        }
    },
);

watch(
    () => enVivo.lineas.value.map((l) => l.texto).join('\n'),
    async () => {
        await nextTick();
        cajaEnVivo.value?.scrollTo({ top: cajaEnVivo.value.scrollHeight });
    },
);

// Reunión terminada cuyo avance y resumen se muestran en el panel lateral.
const reunionMostrada = ref<Reunion | null>(props.ultimaReunion);

// Al empezar a grabar se muestra el panel de la reunión en curso.
watch(estado, (actual) => {
    if (actual === 'escuchando') {
        pestana.value = 'reunion';
    }
});

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

const { tramos } = useLineaDeTiempo(
    computed(() =>
        reunionAbierta.value && reunion.value ? reunion.value.id : null,
    ),
);

const subidasPendientes = computed(() =>
    subidas.value.filter((subida) => subida.estado !== 'subido'),
);

function rango(tramo: TramoReunion): string {
    return `${formatearTiempo(tramo.inicio_segundos)} – ${formatearTiempo(
        tramo.inicio_segundos + tramo.duracion_segundos,
    )}`;
}

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
    pendiente: 'text-slate-500',
    transcribiendo: 'text-cyan-300 animate-pulse',
    listo: 'text-emerald-400',
    error: 'text-rose-400',
};

async function iniciar() {
    await escuchar(titulo.value.trim());

    if (estado.value === 'escuchando') {
        titulo.value = '';
    }
}

function confirmarTerminar() {
    if (
        window.confirm(
            '¿Terminar la reunión? Ya no se podrá agregar más audio y Jarvis generará el acta.',
        )
    ) {
        void terminar();
    }
}
</script>

<template>
    <Head title="Jarvis" />

    <div class="flex h-full flex-1 flex-col p-4">
        <div
            class="relative flex flex-1 flex-col overflow-hidden rounded-xl border border-cyan-500/20 bg-[#04080f] text-cyan-50 lg:flex-row"
        >
            <!-- Rejilla de fondo estilo HUD -->
            <div
                class="pointer-events-none absolute inset-0 bg-[linear-gradient(rgba(34,211,238,0.04)_1px,transparent_1px),linear-gradient(90deg,rgba(34,211,238,0.04)_1px,transparent_1px)] bg-[size:32px_32px]"
            />

            <section
                class="relative flex flex-1 flex-col items-center justify-center gap-6 p-6"
            >
                <div class="w-full max-w-[min(70vh,520px)]">
                    <NucleoJarvis
                        :analizador="analizador"
                        :activo="escuchando"
                    />
                </div>

                <div class="flex flex-col items-center gap-1 text-center">
                    <p
                        class="font-mono text-xs tracking-[0.4em] text-cyan-400/80 uppercase"
                        aria-live="polite"
                    >
                        {{ textoEstado }}
                    </p>
                    <p
                        class="font-mono text-4xl font-light tabular-nums"
                        :class="
                            escuchando
                                ? 'text-cyan-100'
                                : reunionAbierta
                                  ? 'text-cyan-100/70'
                                  : 'text-cyan-100/40'
                        "
                    >
                        {{ formatearTiempo(reunionAbierta ? segundos : 0) }}
                    </p>
                    <p
                        v-if="reunionAbierta && reunion"
                        class="max-w-md truncate text-sm text-cyan-200/60"
                    >
                        {{ reunion.titulo || 'Reunión sin título' }} · tiempo
                        grabado
                    </p>
                </div>

                <div class="flex w-full max-w-md flex-col items-center gap-3">
                    <div
                        v-if="pausaLarga && reunion"
                        class="rounded-lg border border-amber-400/30 bg-amber-950/30 p-3 text-center text-sm text-amber-100"
                    >
                        Esta reunión está en pausa desde las
                        {{
                            formatearHora(
                                reunion.pausada_en ?? reunion.iniciada_en,
                            )
                        }}. Si ahora empieza una reunión distinta, primero
                        <strong>termínala</strong> y luego presiona
                        <strong>Escuchar</strong>.
                    </div>

                    <Input
                        v-if="estado === 'inactivo'"
                        v-model="titulo"
                        placeholder="Título de la reunión (opcional)"
                        maxlength="255"
                        class="border-cyan-500/30 bg-cyan-950/30 text-cyan-50 placeholder:text-cyan-200/40"
                    />

                    <div class="flex flex-wrap justify-center gap-3">
                        <Button
                            v-if="!reunionAbierta"
                            size="lg"
                            :disabled="estado === 'iniciando'"
                            class="bg-cyan-400 text-slate-950 hover:bg-cyan-300"
                            @click="iniciar"
                        >
                            <Mic />
                            Escuchar
                        </Button>
                        <Button
                            v-if="
                                estado === 'escuchando' || estado === 'pausando'
                            "
                            size="lg"
                            variant="outline"
                            :disabled="estado === 'pausando'"
                            class="border-cyan-500/40 bg-transparent text-cyan-100 hover:bg-cyan-950"
                            @click="pausar"
                        >
                            <Pause />
                            Pausar
                        </Button>
                        <Button
                            v-if="
                                estado === 'pausado' || estado === 'reanudando'
                            "
                            size="lg"
                            :disabled="estado === 'reanudando'"
                            class="bg-cyan-400 text-slate-950 hover:bg-cyan-300"
                            @click="reanudar"
                        >
                            <Play />
                            Reanudar
                        </Button>
                        <Button
                            v-if="
                                reunionAbierta && estado !== 'subida-pendiente'
                            "
                            size="lg"
                            variant="destructive"
                            :disabled="
                                estado !== 'escuchando' && estado !== 'pausado'
                            "
                            @click="confirmarTerminar"
                        >
                            <Square />
                            Terminar reunión
                        </Button>
                        <Button
                            v-if="estado === 'subida-pendiente'"
                            size="lg"
                            variant="outline"
                            class="border-cyan-500/40 bg-transparent text-cyan-100 hover:bg-cyan-950"
                            @click="reintentar"
                        >
                            <RotateCw />
                            Reintentar
                        </Button>
                    </div>

                    <p
                        v-if="error"
                        class="flex items-center gap-2 text-sm text-rose-300"
                        role="alert"
                    >
                        <CircleAlert class="size-4 shrink-0" />
                        {{ error }}
                    </p>
                </div>

                <div
                    v-if="
                        grabacion.enVivo &&
                        (reunionAbierta || enVivo.lineas.value.length)
                    "
                    class="flex w-full max-w-3xl flex-col gap-2"
                >
                    <div
                        class="flex items-center gap-2 font-mono text-[11px] tracking-[0.3em] text-cyan-400/70 uppercase"
                    >
                        <span
                            class="size-2 rounded-full"
                            :class="
                                enVivo.estado.value === 'activo'
                                    ? 'animate-pulse bg-rose-500'
                                    : enVivo.estado.value === 'conectando'
                                      ? 'animate-pulse bg-amber-400'
                                      : 'bg-slate-600'
                            "
                        />
                        En vivo
                        <span
                            class="tracking-normal text-cyan-200/40 normal-case"
                        >
                            · sin separar hablantes · el acta usa la
                            transcripción completa
                        </span>
                    </div>
                    <div
                        ref="cajaEnVivo"
                        class="h-36 overflow-y-auto rounded-lg border border-cyan-500/20 bg-black/40 p-3 text-base leading-relaxed"
                        aria-live="polite"
                    >
                        <p
                            v-if="!enVivo.lineas.value.length"
                            class="text-sm text-cyan-200/40"
                        >
                            {{
                                enVivo.estado.value === 'conectando'
                                    ? 'Conectando…'
                                    : enVivo.estado.value === 'activo'
                                      ? 'Escuchando… el texto aparecerá aquí.'
                                      : estado === 'pausado'
                                        ? 'En pausa.'
                                        : ''
                            }}
                        </p>
                        <p
                            v-for="linea in enVivo.lineas.value"
                            :key="linea.id"
                            :class="
                                linea.final
                                    ? 'text-cyan-50/90'
                                    : 'text-cyan-100/50 italic'
                            "
                        >
                            {{ linea.texto || '…' }}
                        </p>
                    </div>
                    <p
                        v-if="enVivo.mensaje.value"
                        class="flex items-center gap-2 text-xs text-amber-300"
                    >
                        <CircleAlert class="size-3.5 shrink-0" />
                        {{ enVivo.mensaje.value }}
                    </p>
                </div>
            </section>

            <aside
                class="relative flex w-full flex-col gap-4 border-t border-cyan-500/20 bg-cyan-950/10 p-5 lg:w-[28rem] lg:border-t-0 lg:border-l"
            >
                <div
                    class="flex gap-1 border-b border-cyan-500/20"
                    role="tablist"
                >
                    <button
                        v-for="opcion in [
                            { valor: 'reunion', texto: 'Reunión' },
                            { valor: 'chat', texto: 'Chat' },
                        ] as const"
                        :key="opcion.valor"
                        type="button"
                        role="tab"
                        :aria-selected="pestana === opcion.valor"
                        class="-mb-px border-b-2 px-3 py-2 font-mono text-xs tracking-[0.3em] uppercase transition-colors"
                        :class="
                            pestana === opcion.valor
                                ? 'border-cyan-400 text-cyan-300'
                                : 'border-transparent text-cyan-200/40 hover:text-cyan-200/80'
                        "
                        @click="pestana = opcion.valor"
                    >
                        {{ opcion.texto }}
                    </button>
                </div>

                <ChatJarvis
                    v-show="pestana === 'chat'"
                    :reunion-id="reunionAbierta && reunion ? reunion.id : null"
                />

                <div v-show="pestana === 'reunion'" class="flex flex-col gap-4">
                    <h2
                        class="font-mono text-xs tracking-[0.3em] text-cyan-400/80 uppercase"
                    >
                        {{
                            reunionAbierta
                                ? estado === 'pausado'
                                    ? 'Reunión en pausa'
                                    : 'Reunión en curso'
                                : reunionMostrada
                                  ? 'Última reunión'
                                  : 'Reunión'
                        }}
                    </h2>

                    <template v-if="reunion && reunionAbierta">
                        <dl class="grid grid-cols-2 gap-y-2 text-sm">
                            <dt class="text-cyan-200/50">Título</dt>
                            <dd class="truncate text-right">
                                {{ reunion.titulo || 'Sin título' }}
                            </dd>
                            <dt class="text-cyan-200/50">Inicio</dt>
                            <dd class="text-right">
                                {{ formatearHora(reunion.iniciada_en) }}
                            </dd>
                            <dt class="text-cyan-200/50">Grabado</dt>
                            <dd class="text-right tabular-nums">
                                {{ formatearTiempo(segundos) }}
                            </dd>
                        </dl>

                        <p
                            v-if="estado === 'pausado'"
                            class="text-sm text-cyan-200/60"
                        >
                            La reunión sigue abierta. Presiona
                            <strong class="text-cyan-100">Reanudar</strong>
                            para seguir grabando en ella o
                            <strong class="text-cyan-100"
                                >Terminar reunión</strong
                            >
                            para generar el acta.
                        </p>

                        <div class="flex flex-col gap-2">
                            <h3
                                class="font-mono text-xs tracking-[0.3em] text-cyan-400/60 uppercase"
                            >
                                Línea de tiempo
                            </h3>
                            <ol
                                v-if="tramos.length"
                                class="flex max-h-[55vh] flex-col gap-3 overflow-y-auto pr-1 text-sm"
                            >
                                <li
                                    v-for="tramo in tramos"
                                    :key="tramo.orden"
                                    class="border-l-2 border-cyan-500/40 pl-3"
                                >
                                    <p
                                        class="font-mono text-[11px] text-cyan-400/60"
                                    >
                                        {{ rango(tramo) }}
                                    </p>
                                    <template v-if="tramo.resumen">
                                        <p class="font-medium text-cyan-50">
                                            {{ tramo.resumen.tema }}
                                        </p>
                                        <p class="text-cyan-100/70">
                                            {{ tramo.resumen.resumen }}
                                        </p>
                                    </template>
                                    <p
                                        v-else-if="tramo.estado === 'error'"
                                        class="text-xs text-rose-300"
                                        :title="tramo.error ?? undefined"
                                    >
                                        No se pudo transcribir este tramo.
                                    </p>
                                    <p
                                        v-else
                                        class="flex items-center gap-2 text-xs text-cyan-300/70"
                                    >
                                        <LoaderCircle
                                            class="size-3 animate-spin"
                                        />
                                        {{
                                            tramo.estado === 'listo'
                                                ? 'Resumiendo…'
                                                : 'Transcribiendo…'
                                        }}
                                    </p>
                                </li>
                            </ol>
                            <p v-else class="text-sm text-cyan-200/50">
                                Cada minuto grabado aparecerá aquí con su tema y
                                un resumen corto.
                            </p>
                            <p
                                v-if="escuchando && tramos.length"
                                class="text-xs text-cyan-200/40"
                            >
                                Minuto en curso… se resume al completarse.
                            </p>
                            <p
                                v-if="subidasPendientes.length"
                                class="text-xs"
                                :class="
                                    subidasPendientes.some(
                                        (s) => s.estado === 'error',
                                    )
                                        ? 'text-rose-300'
                                        : 'text-cyan-200/50'
                                "
                            >
                                {{
                                    subidasPendientes.some(
                                        (s) => s.estado === 'error',
                                    )
                                        ? 'No se pudo subir parte del audio.'
                                        : 'Subiendo audio…'
                                }}
                            </p>
                        </div>
                    </template>

                    <template v-else-if="reunionMostrada">
                        <dl class="grid grid-cols-2 gap-y-2 text-sm">
                            <dt class="text-cyan-200/50">Título</dt>
                            <dd class="truncate text-right">
                                {{ reunionMostrada.titulo || 'Sin título' }}
                            </dd>
                            <dt class="text-cyan-200/50">Inicio</dt>
                            <dd class="text-right">
                                {{ formatearHora(reunionMostrada.iniciada_en) }}
                            </dd>
                            <dt class="text-cyan-200/50">Duración</dt>
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
                            class="flex flex-col gap-3 rounded-lg border border-cyan-400/30 bg-cyan-950/30 p-4 text-sm"
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
                                class="h-1 overflow-hidden rounded bg-cyan-950"
                            >
                                <div
                                    class="h-full bg-cyan-400 transition-all"
                                    :style="{
                                        width: `${fragmentos.length ? (transcritos / fragmentos.length) * 100 : 0}%`,
                                    }"
                                />
                            </div>
                        </div>

                        <div
                            v-else-if="reunionMostrada.estado === 'error'"
                            class="flex flex-col gap-3 rounded-lg border border-rose-400/30 bg-rose-950/20 p-4 text-sm"
                        >
                            <p
                                class="flex items-center gap-2 font-medium text-rose-200"
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
                                class="self-start bg-cyan-400 text-slate-950 hover:bg-cyan-300"
                                @click="reprocesar"
                            >
                                <RotateCw />
                                Reintentar
                            </Button>
                        </div>

                        <p
                            v-else-if="reunionMostrada.estado === 'completada'"
                            class="flex items-center gap-2 text-sm font-medium text-emerald-300"
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
                                class="flex items-center justify-between gap-2 rounded border border-cyan-500/10 bg-cyan-950/20 px-3 py-1.5"
                                :title="fragmento.error ?? undefined"
                            >
                                <span class="font-mono">
                                    #{{
                                        String(fragmento.orden).padStart(2, '0')
                                    }}
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
                                resumen &&
                                reunionMostrada.estado === 'completada'
                            "
                            class="flex flex-col gap-3 text-sm"
                        >
                            <p class="leading-relaxed text-cyan-50/90">
                                {{ resumen.resumen_ejecutivo }}
                            </p>
                            <ul
                                v-if="resumen.temas_tratados.length"
                                class="flex flex-wrap gap-1.5"
                            >
                                <li
                                    v-for="tema in resumen.temas_tratados"
                                    :key="tema"
                                    class="rounded-full border border-cyan-500/30 px-2.5 py-0.5 text-xs text-cyan-100/80"
                                >
                                    {{ tema }}
                                </li>
                            </ul>
                            <p class="text-xs text-cyan-200/50">
                                {{ resumen.acuerdos.length }} acuerdo(s) ·
                                {{ resumen.pendientes.length }} pendiente(s)
                            </p>
                        </div>

                        <Button
                            v-if="reunionMostrada.estado !== 'procesando'"
                            size="sm"
                            variant="outline"
                            class="self-start border-cyan-500/40 bg-transparent text-cyan-100 hover:bg-cyan-950"
                            as-child
                        >
                            <Link :href="verReunion(reunionMostrada.id)">
                                <FileText />
                                Ver acta completa
                            </Link>
                        </Button>
                    </template>

                    <p v-else class="text-sm text-cyan-200/50">
                        Presiona <strong class="text-cyan-100">Escuchar</strong>
                        al iniciar la reunión y
                        <strong class="text-cyan-100">Terminar</strong> al
                        finalizar.
                    </p>
                </div>
            </aside>
        </div>
    </div>
</template>
