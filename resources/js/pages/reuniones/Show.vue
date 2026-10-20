<script setup lang="ts">
import { Head, http, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CircleAlert,
    Download,
    FileText,
    Image as ImageIcon,
    LoaderCircle,
    Printer,
    RotateCw,
    Sparkles,
} from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import {
    documento as descargarDocumento,
    index as historial,
    show as verReunion,
} from '@/actions/App/Http/Controllers/HistorialController';
import {
    regenerarResumen,
    reprocesar,
} from '@/actions/App/Http/Controllers/ReunionController';
import ActaReunion from '@/components/jarvis/ActaReunion.vue';
import ChatReunion from '@/components/jarvis/ChatReunion.vue';
import EstadoReunionBadge from '@/components/jarvis/EstadoReunionBadge.vue';
import { Button } from '@/components/ui/button';
import type { MensajeChat } from '@/composables/useChat';
import { formatearFecha, formatearHora, formatearTiempo } from '@/lib/formato';
import type { ContenidoResumen, Reunion, SegmentoTranscripcion } from '@/types';

type VersionResumen = {
    version: number;
    modelo: string;
    generado_en: string | null;
};

const props = defineProps<{
    reunion: Reunion & {
        costo_audio_segundos: number;
        costo_tokens: number;
    };
    resumen: (VersionResumen & { contenido: ContenidoResumen }) | null;
    versiones: VersionResumen[];
    documentos: { nombre: string; archivo: string; imagen: boolean }[];
    chat: MensajeChat[];
    segmentos: SegmentoTranscripcion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Reuniones', href: historial() }],
    },
});

const vista = ref<'acta' | 'transcripcion' | 'chat'>('acta');
const enviando = ref(false);
const error = ref<string | null>(null);

let recarga: number | undefined;

// Mientras se transcribe o se genera el resumen, la página se refresca sola.
watch(
    () => props.reunion.estado,
    (estado) => {
        window.clearInterval(recarga);

        if (estado === 'procesando') {
            recarga = window.setInterval(
                () =>
                    router.reload({
                        only: ['reunion', 'resumen', 'versiones', 'segmentos'],
                    }),
                3000,
            );
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => window.clearInterval(recarga));

async function ejecutar(url: string) {
    enviando.value = true;
    error.value = null;

    try {
        await http.getClient().request({
            method: 'post',
            url,
            headers: { Accept: 'application/json' },
        });
        // Al regenerar se muestra la versión más reciente.
        router.visit(verReunion.url(props.reunion.id), {
            preserveScroll: true,
        });
    } catch {
        error.value = 'No se pudo completar la acción. Intenta de nuevo.';
    } finally {
        enviando.value = false;
    }
}

function imprimir() {
    window.print();
}

function cambiarVersion(evento: Event) {
    const version = (evento.target as HTMLSelectElement).value;

    router.visit(verReunion.url(props.reunion.id, { query: { version } }), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="reunion.titulo || 'Reunión'" />

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
        <Link
            :href="historial()"
            class="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground print:hidden"
        >
            <ArrowLeft class="size-4" /> Todas las reuniones
        </Link>

        <header class="flex flex-col gap-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold">
                        {{ reunion.titulo || 'Reunión sin título' }}
                    </h1>
                    <p
                        class="text-sm text-muted-foreground first-letter:uppercase"
                    >
                        {{ formatearFecha(reunion.iniciada_en) }} ·
                        {{ formatearHora(reunion.iniciada_en) }}
                        <template v-if="reunion.duracion_segundos">
                            · {{ formatearTiempo(reunion.duracion_segundos) }}
                        </template>
                    </p>
                </div>
                <EstadoReunionBadge :reunion="reunion" />
            </div>

            <div
                class="flex flex-wrap items-center gap-2 print:hidden"
                v-if="reunion.estado !== 'grabando'"
            >
                <select
                    v-if="versiones.length > 1 && resumen"
                    :value="resumen.version"
                    class="h-8 rounded-md border bg-background px-2 text-sm"
                    aria-label="Versión del resumen"
                    @change="cambiarVersion"
                >
                    <option
                        v-for="version in versiones"
                        :key="version.version"
                        :value="version.version"
                    >
                        Versión {{ version.version }}
                        <template v-if="version.generado_en">
                            · {{ formatearHora(version.generado_en) }}
                        </template>
                    </option>
                </select>

                <Button
                    v-if="reunion.estado === 'completada'"
                    variant="outline"
                    size="sm"
                    :disabled="enviando"
                    @click="ejecutar(regenerarResumen.url(reunion.id))"
                >
                    <Sparkles /> Regenerar resumen
                </Button>
                <Button
                    v-if="reunion.estado === 'error'"
                    size="sm"
                    :disabled="enviando"
                    @click="ejecutar(reprocesar.url(reunion.id))"
                >
                    <RotateCw /> Reintentar
                </Button>
                <Button
                    v-if="resumen"
                    variant="ghost"
                    size="sm"
                    @click="imprimir"
                >
                    <Printer /> Imprimir
                </Button>
            </div>

            <p
                v-if="error"
                class="flex items-center gap-2 text-sm text-rose-600"
                role="alert"
            >
                <CircleAlert class="size-4" /> {{ error }}
            </p>
        </header>

        <div
            v-if="reunion.estado === 'procesando'"
            class="flex items-center gap-2 rounded-lg border border-hud-acento/30 bg-hud-acento/5 p-4 text-sm"
            aria-live="polite"
        >
            <LoaderCircle class="size-4 animate-spin" />
            {{
                reunion.transcripcion_lista
                    ? 'Generando el resumen con IA…'
                    : 'Transcribiendo el audio…'
            }}
        </div>

        <div
            v-else-if="reunion.estado === 'error'"
            class="flex items-center gap-2 rounded-lg border border-rose-300 bg-rose-500/5 p-4 text-sm"
        >
            <CircleAlert class="size-4 text-rose-600" />
            Hubo un problema al procesar la reunión. Presiona "Reintentar".
        </div>

        <section v-if="documentos.length" class="flex flex-col gap-2">
            <h2 class="text-sm font-medium text-muted-foreground">
                Documentos de la reunión
            </h2>
            <ul class="flex flex-wrap gap-2">
                <li v-for="documento in documentos" :key="documento.archivo">
                    <a
                        :href="
                            descargarDocumento.url({
                                reunion: reunion.id,
                                archivo: documento.archivo,
                            })
                        "
                        class="flex max-w-full items-center gap-2 rounded-lg border px-3 py-1.5 text-sm transition-colors hover:border-hud-acento/60 hover:bg-hud-acento/5"
                        download
                    >
                        <component
                            :is="documento.imagen ? ImageIcon : FileText"
                            class="size-4 shrink-0 text-hud-acento"
                        />
                        <span class="truncate">{{ documento.nombre }}</span>
                        <Download
                            class="size-3.5 shrink-0 text-muted-foreground print:hidden"
                        />
                    </a>
                </li>
            </ul>
        </section>

        <div class="flex gap-1 border-b print:hidden" role="tablist">
            <button
                v-for="opcion in [
                    { valor: 'acta', texto: 'Acta' },
                    { valor: 'transcripcion', texto: 'Transcripción' },
                    { valor: 'chat', texto: 'Chat' },
                ] as const"
                :key="opcion.valor"
                role="tab"
                :aria-selected="vista === opcion.valor"
                class="-mb-px border-b-2 px-3 py-2 text-sm transition-colors"
                :class="
                    vista === opcion.valor
                        ? 'border-hud-acento font-medium text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                @click="vista = opcion.valor"
            >
                {{ opcion.texto }}
            </button>
        </div>

        <template v-if="vista === 'acta'">
            <ActaReunion v-if="resumen" :acta="resumen.contenido" />
            <p v-else class="text-sm text-muted-foreground">
                El acta aparecerá aquí cuando termine el procesamiento.
            </p>
            <p v-if="resumen" class="text-xs text-muted-foreground">
                Generado por {{ resumen.modelo }} · versión
                {{ resumen.version }}. Revisa el acta antes de compartirla: la
                IA puede cometer errores.
            </p>
        </template>

        <ChatReunion v-else-if="vista === 'chat'" :mensajes="chat" />

        <template v-else>
            <p v-if="!segmentos.length" class="text-sm text-muted-foreground">
                La transcripción aparecerá aquí cuando termine el procesamiento.
            </p>
            <ol v-else class="flex flex-col gap-4 text-sm">
                <li
                    v-for="(segmento, indice) in segmentos"
                    :key="indice"
                    class="grid grid-cols-[4rem_1fr] gap-3"
                >
                    <span class="font-mono text-xs text-muted-foreground">
                        {{
                            formatearTiempo(
                                Math.floor(segmento.inicio_segundos),
                            )
                        }}
                    </span>
                    <div>
                        <p
                            v-if="segmento.hablante"
                            class="text-xs font-medium text-hud-acento-texto dark:text-hud-acento-texto"
                        >
                            Hablante {{ segmento.hablante }}
                        </p>
                        <p class="leading-relaxed">{{ segmento.texto }}</p>
                    </div>
                </li>
            </ol>
        </template>

        <p class="text-xs text-muted-foreground print:hidden">
            Consumo: {{ Math.ceil(reunion.costo_audio_segundos / 60) }} min de
            audio transcrito ·
            {{ reunion.costo_tokens.toLocaleString('es-PE') }}
            tokens.
        </p>
    </div>
</template>
