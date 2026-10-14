<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    CircleAlert,
    CircleCheck,
    LoaderCircle,
    Mic,
    RotateCw,
    Square,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import NucleoJarvis from '@/components/jarvis/NucleoJarvis.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useGrabadora } from '@/composables/useGrabadora';
import type { EstadoSubida } from '@/composables/useGrabadora';
import { useSeguimientoReunion } from '@/composables/useSeguimientoReunion';
import { jarvis } from '@/routes';
import type { ConfigGrabacion, EstadoFragmento, Reunion } from '@/types';

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
const pendiente = ref<Reunion | null>(props.reunionActiva);

const {
    estado,
    reunion,
    subidas,
    error,
    analizador,
    segundos,
    escuchar,
    terminar,
    terminarSinGrabar,
    reintentar,
} = useGrabadora(props.grabacion);

// Reunión terminada cuyo avance y transcripción se muestran en el panel lateral.
const reunionMostrada = ref<Reunion | null>(props.ultimaReunion);

watch(reunion, (actual) => {
    reunionMostrada.value =
        actual && actual.estado !== 'grabando' ? actual : null;
});

const { fragmentos, segmentos, reprocesando, reprocesar } =
    useSeguimientoReunion(reunionMostrada);

const escuchando = computed(() => estado.value === 'escuchando');
const ocupado = computed(() =>
    ['iniciando', 'terminando'].includes(estado.value),
);
const transcritos = computed(
    () => fragmentos.value.filter((f) => f.estado === 'listo').length,
);

const textoEstado = computed(
    () =>
        ({
            inactivo: 'En espera',
            iniciando: 'Iniciando',
            escuchando: 'Escuchando',
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

const iconoSubida: Record<EstadoSubida, string> = {
    pendiente: 'text-slate-500',
    subiendo: 'text-cyan-300 animate-pulse',
    subido: 'text-emerald-400',
    error: 'text-rose-400',
};

function formatearTiempo(total: number): string {
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const dosDigitos = (n: number) => String(n).padStart(2, '0');

    return h > 0
        ? `${h}:${dosDigitos(m)}:${dosDigitos(s)}`
        : `${dosDigitos(m)}:${dosDigitos(s)}`;
}

function formatearHora(iso: string): string {
    return new Date(iso).toLocaleTimeString('es-PE', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

async function iniciar() {
    await escuchar({ titulo: titulo.value.trim() });

    if (estado.value === 'escuchando') {
        titulo.value = '';
    }
}

async function reanudarPendiente() {
    if (!pendiente.value) {
        return;
    }

    await escuchar({ continuar: pendiente.value });

    if (estado.value === 'escuchando') {
        pendiente.value = null;
    }
}

async function cerrarPendiente() {
    if (!pendiente.value) {
        return;
    }

    const reunionPendiente = pendiente.value;
    pendiente.value = null;
    await terminarSinGrabar(reunionPendiente);
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
                            escuchando ? 'text-cyan-100' : 'text-cyan-100/40'
                        "
                    >
                        {{ formatearTiempo(escuchando ? segundos : 0) }}
                    </p>
                </div>

                <div class="flex w-full max-w-md flex-col items-center gap-3">
                    <Input
                        v-if="estado === 'inactivo' && !pendiente"
                        v-model="titulo"
                        placeholder="Título de la reunión (opcional)"
                        maxlength="255"
                        class="border-cyan-500/30 bg-cyan-950/30 text-cyan-50 placeholder:text-cyan-200/40"
                    />

                    <div class="flex gap-3">
                        <Button
                            v-if="!escuchando"
                            size="lg"
                            :disabled="
                                ocupado ||
                                estado === 'subida-pendiente' ||
                                !!pendiente
                            "
                            class="bg-cyan-400 text-slate-950 hover:bg-cyan-300"
                            @click="iniciar"
                        >
                            <Mic />
                            Escuchar
                        </Button>
                        <Button
                            v-else
                            size="lg"
                            variant="destructive"
                            @click="terminar"
                        >
                            <Square />
                            Terminar
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
            </section>

            <aside
                class="relative flex w-full flex-col gap-4 border-t border-cyan-500/20 bg-cyan-950/10 p-5 lg:w-96 lg:border-t-0 lg:border-l"
            >
                <h2
                    class="font-mono text-xs tracking-[0.3em] text-cyan-400/80 uppercase"
                >
                    Reunión
                </h2>

                <div
                    v-if="pendiente"
                    class="flex flex-col gap-3 rounded-lg border border-amber-400/30 bg-amber-950/20 p-4 text-sm"
                >
                    <p class="text-amber-100">
                        Hay una reunión sin terminar, iniciada a las
                        {{ formatearHora(pendiente.iniciada_en) }} con
                        {{ pendiente.fragmentos_subidos ?? 0 }} fragmento(s)
                        guardados.
                    </p>
                    <div class="flex gap-2">
                        <Button
                            size="sm"
                            class="bg-cyan-400 text-slate-950 hover:bg-cyan-300"
                            @click="reanudarPendiente"
                        >
                            Seguir escuchando
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            class="border-amber-400/40 bg-transparent text-amber-100 hover:bg-amber-950"
                            @click="cerrarPendiente"
                        >
                            Terminarla
                        </Button>
                    </div>
                </div>

                <template v-if="reunion && estado !== 'inactivo'">
                    <dl class="grid grid-cols-2 gap-y-2 text-sm">
                        <dt class="text-cyan-200/50">Título</dt>
                        <dd class="truncate text-right">
                            {{ reunion.titulo || 'Sin título' }}
                        </dd>
                        <dt class="text-cyan-200/50">Inicio</dt>
                        <dd class="text-right">
                            {{ formatearHora(reunion.iniciada_en) }}
                        </dd>
                    </dl>

                    <div v-if="subidas.length" class="flex flex-col gap-2">
                        <h3
                            class="font-mono text-xs tracking-[0.3em] text-cyan-400/60 uppercase"
                        >
                            Fragmentos
                        </h3>
                        <ul class="flex flex-col gap-1.5 text-sm">
                            <li
                                v-for="subida in subidas"
                                :key="subida.orden"
                                class="flex items-center justify-between gap-2 rounded border border-cyan-500/10 bg-cyan-950/20 px-3 py-1.5"
                                :title="subida.error"
                            >
                                <span class="font-mono">
                                    #{{ String(subida.orden).padStart(2, '0') }}
                                </span>
                                <span class="text-cyan-200/50 tabular-nums">
                                    {{ formatearTiempo(subida.duracion) }} ·
                                    {{
                                        (subida.tamano / 1024 / 1024).toFixed(1)
                                    }}
                                    MB
                                </span>
                                <span
                                    class="text-xs"
                                    :class="iconoSubida[subida.estado]"
                                >
                                    {{ subida.estado }}
                                </span>
                            </li>
                        </ul>
                    </div>

                    <p v-else-if="escuchando" class="text-sm text-cyan-200/50">
                        El audio se guarda en fragmentos de
                        {{ Math.round(grabacion.fragmentoSegundos / 60) }}
                        minutos. El primero aparecerá aquí al completarse.
                    </p>
                </template>

                <template v-else-if="reunionMostrada && !pendiente">
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
                            Transcribiendo {{ transcritos }} de
                            {{ fragmentos.length || '…' }}
                        </p>
                        <div class="h-1 overflow-hidden rounded bg-cyan-950">
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
                            Algunos fragmentos no se pudieron transcribir
                        </p>
                        <Button
                            size="sm"
                            :disabled="reprocesando"
                            class="self-start bg-cyan-400 text-slate-950 hover:bg-cyan-300"
                            @click="reprocesar"
                        >
                            <RotateCw />
                            Reintentar transcripción
                        </Button>
                    </div>

                    <p
                        v-else-if="reunionMostrada.estado === 'completada'"
                        class="flex items-center gap-2 text-sm font-medium text-emerald-300"
                    >
                        <CircleCheck class="size-4" />
                        Transcripción lista
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

                    <ol
                        v-if="segmentos.length"
                        class="flex max-h-[55vh] flex-col gap-3 overflow-y-auto pr-1 text-sm"
                    >
                        <li
                            v-for="(segmento, indice) in segmentos"
                            :key="indice"
                            class="flex flex-col gap-0.5"
                        >
                            <span
                                class="font-mono text-[11px] text-cyan-400/60"
                            >
                                {{
                                    formatearTiempo(
                                        Math.floor(segmento.inicio_segundos),
                                    )
                                }}
                                <template v-if="segmento.hablante">
                                    · Hablante {{ segmento.hablante }}
                                </template>
                            </span>
                            <p class="leading-relaxed text-cyan-50/90">
                                {{ segmento.texto }}
                            </p>
                        </li>
                    </ol>
                </template>

                <p v-else-if="!pendiente" class="text-sm text-cyan-200/50">
                    Presiona <strong class="text-cyan-100">Escuchar</strong>
                    al iniciar la reunión y
                    <strong class="text-cyan-100">Terminar</strong> al
                    finalizar.
                </p>
            </aside>
        </div>
    </div>
</template>
