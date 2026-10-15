<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    CircleAlert,
    ClipboardList,
    FileText,
    Check,
    LoaderCircle,
    Mic,
    Paperclip,
    Plus,
    SendHorizontal,
    Square,
    Volume2,
    VolumeX,
    X,
} from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import { computed, nextTick, ref, toRef, watch } from 'vue';
import { show as verReunion } from '@/actions/App/Http/Controllers/HistorialController';
import { Button } from '@/components/ui/button';
import { PEDIDO_RESUMEN_DOCUMENTO, useChat } from '@/composables/useChat';
import type { MensajeChat } from '@/composables/useChat';
import { useDictado } from '@/composables/useDictado';
import { useVoz } from '@/composables/useVoz';
import { formatearTamano, formatearTiempo } from '@/lib/formato';
import { markdownSeguro } from '@/lib/markdown';
import type {
    ConfigDocumentos,
    ContenidoResumen,
    Reunion,
    TramoReunion,
} from '@/types';

const props = withDefaults(
    defineProps<{
        // Reunión a la que pertenece el chat (abierta o recién terminada); null = chat general.
        reunionId: string | null;
        reunionAbierta?: boolean;
        // Estado de esa reunión según el servidor (para el acta).
        reunion?: Reunion | null;
        tramos?: TramoReunion[];
        acta?: ContenidoResumen | null;
        escuchando?: boolean;
        documentos: ConfigDocumentos;
        // Duración máxima de un dictado por micrófono.
        dictadoMaxSegundos?: number;
    }>(),
    {
        reunionAbierta: false,
        reunion: null,
        tramos: () => [],
        acta: null,
        escuchando: false,
        dictadoMaxSegundos: 120,
    },
);

const emit = defineEmits<{
    // Pide dejar el chat de la reunión terminada y pasar al chat general.
    salirDeReunion: [];
}>();

const { mensajes, enviando, error, nueva, enviar } = useChat(
    toRef(props, 'reunionId'),
);

const voz = useVoz();
const dictado = useDictado(props.dictadoMaxSegundos);
const cajaTexto = ref<HTMLTextAreaElement | null>(null);

// Con Enter (o ➤) el dictado se envía apenas se transcribe; con ✓ queda en la caja.
let enviarAlTranscribir = false;

function dictar() {
    voz.detener();
    enviarAlTranscribir = false;

    void dictado.iniciar(async (dicho) => {
        // Se agrega a lo ya escrito para poder dictar en varias partes.
        texto.value = texto.value.trim()
            ? `${texto.value.trim()} ${dicho}`
            : dicho;

        if (enviarAlTranscribir) {
            enviarAlTranscribir = false;
            await mandar();

            return;
        }

        await nextTick();
        cajaTexto.value?.focus();
    });
}

function terminarDictadoYEnviar() {
    enviarAlTranscribir = true;
    dictado.terminar();
}

function alEnviarFormulario() {
    if (dictado.estado.value === 'grabando') {
        terminarDictadoYEnviar();
    } else {
        void mandar();
    }
}

// Mientras se dicta no hay caja de texto con foco: el teclado se escucha en la ventana.
useEventListener(window, 'keydown', (evento: KeyboardEvent) => {
    if (dictado.estado.value !== 'grabando') {
        return;
    }

    if (evento.key === 'Enter' && !evento.shiftKey) {
        evento.preventDefault();
        terminarDictadoYEnviar();
    } else if (evento.key === 'Escape') {
        evento.preventDefault();
        dictado.cancelar();
    }
});

const texto = ref('');
const documento = ref<File | null>(null);
const arrastrando = ref(false);
const lista = ref<HTMLElement | null>(null);
const selectorArchivo = ref<HTMLInputElement | null>(null);
// Solo se baja sola la lista si el usuario no subió a leer algo anterior.
const pegadoAlFinal = ref(true);

type Entrada =
    | { tipo: 'mensaje'; clave: string; hora: number; mensaje: MensajeChat }
    | { tipo: 'tramo'; clave: string; hora: number; tramo: TramoReunion }
    | { tipo: 'acta'; clave: string; hora: number };

const hora = (iso: string | null | undefined) =>
    iso ? Date.parse(iso) : Number.MAX_SAFE_INTEGER;

/**
 * Todo lo de la reunión en una sola conversación y en orden: los mensajes,
 * el resumen de cada minuto grabado y, al terminar, el acta.
 */
const entradas = computed<Entrada[]>(() => {
    const lista: Entrada[] = [
        ...mensajes.value.map((mensaje, indice) => ({
            tipo: 'mensaje' as const,
            clave: `m${indice}`,
            hora: hora(mensaje.creado_en),
            mensaje,
        })),
        ...props.tramos.map((tramo) => ({
            tipo: 'tramo' as const,
            clave: `t${tramo.orden}`,
            hora: hora(tramo.creado_en),
            tramo,
        })),
    ];

    const terminada =
        props.reunion &&
        ['procesando', 'completada', 'error'].includes(props.reunion.estado);

    if (props.reunionId && !props.reunionAbierta && terminada) {
        lista.push({
            tipo: 'acta',
            clave: 'acta',
            hora: hora(props.reunion?.finalizada_en),
        });
    }

    // sort es estable: a igual hora se respeta el orden en que se agregaron.
    return lista.sort((a, b) => a.hora - b.hora);
});

const chatDeReunionTerminada = computed(
    () => props.reunionId !== null && !props.reunionAbierta,
);

function rango(tramo: TramoReunion): string {
    return `${formatearTiempo(tramo.inicio_segundos)} – ${formatearTiempo(
        tramo.inicio_segundos + tramo.duracion_segundos,
    )}`;
}

function alPulsarNueva() {
    voz.detener();

    if (chatDeReunionTerminada.value) {
        emit('salirDeReunion');
    } else {
        nueva();
    }
}

const formatosAceptados = computed(() =>
    props.documentos.formatos.map((formato) => `.${formato}`).join(','),
);

const nombresFormatos = computed(() =>
    props.documentos.formatos
        .map((formato) => formato.toUpperCase())
        .join(', '),
);

const sugerencias = computed(() =>
    props.reunionId
        ? [
              '¿Qué se ha hablado hasta ahora?',
              'Haz un resumen parcial de la reunión',
          ]
        : [
              'Últimos videos del canal @infobae',
              '¿Qué acordamos en la última reunión?',
          ],
);

async function bajar(forzar = false) {
    if (!forzar && !pegadoAlFinal.value) {
        return;
    }

    await nextTick();
    lista.value?.scrollTo({ top: lista.value.scrollHeight });
}

function alDesplazar() {
    const caja = lista.value;

    if (caja) {
        pegadoAlFinal.value =
            caja.scrollHeight - caja.scrollTop - caja.clientHeight < 80;
    }
}

function adjuntar(archivo: File | undefined) {
    if (!archivo || enviando.value) {
        return;
    }

    const extension = archivo.name.split('.').pop()?.toLowerCase() ?? '';

    if (!props.documentos.formatos.includes(extension)) {
        error.value = `Formato no admitido. Usa ${nombresFormatos.value}.`;

        return;
    }

    if (archivo.size > props.documentos.maxKb * 1024) {
        error.value = `El documento pesa ${formatearTamano(archivo.size)} y el máximo es ${formatearTamano(props.documentos.maxKb * 1024)}.`;

        return;
    }

    error.value = null;
    documento.value = archivo;
}

function alElegirArchivo(evento: Event) {
    const campo = evento.target as HTMLInputElement;
    adjuntar(campo.files?.[0]);
    // Permite volver a elegir el mismo archivo después de quitarlo.
    campo.value = '';
}

function alSalirArrastre(evento: DragEvent) {
    const contenedor = evento.currentTarget as HTMLElement;

    // Pasar sobre un elemento interno también dispara dragleave; solo cuenta salir del chat.
    if (!contenedor.contains(evento.relatedTarget as Node | null)) {
        arrastrando.value = false;
    }
}

function alSoltar(evento: DragEvent) {
    arrastrando.value = false;
    adjuntar(evento.dataTransfer?.files[0]);
}

async function mandar(mensaje = texto.value) {
    const archivo = documento.value;

    if ((!mensaje.trim() && !archivo) || enviando.value) {
        return;
    }

    const escrito = texto.value;
    texto.value = '';
    documento.value = null;
    pegadoAlFinal.value = true;

    voz.detener();

    // Si no llegó a Jarvis, se devuelve lo que el usuario había preparado.
    if (!(await enviar(mensaje, archivo))) {
        texto.value = escrito;
        documento.value = archivo;

        return;
    }

    leerUltimaRespuesta();
}

/**
 * Deja en la caja una pregunta dicha en la reunión ("Jarvis, busca…") para
 * que el usuario la revise y la envíe con Enter.
 */
async function proponer(pregunta: string) {
    texto.value = texto.value.trim()
        ? `${texto.value.trim()} ${pregunta}`
        : pregunta;
    await nextTick();
    cajaTexto.value?.focus();
}

defineExpose({ proponer });

/**
 * Con la lectura automática activada, Jarvis lee su respuesta al terminarla.
 * Mientras se graba no lo hace: los parlantes se colarían en la grabación.
 */
function leerUltimaRespuesta() {
    const indice = mensajes.value.length - 1;
    const ultima = mensajes.value[indice];

    if (
        voz.automatica.value &&
        !props.escuchando &&
        ultima?.rol === 'jarvis' &&
        ultima.contenido.trim()
    ) {
        void voz.reproducir(`m${indice}`, ultima.contenido);
    }
}

function alPresionarTecla(evento: KeyboardEvent) {
    // Enter envía; Shift + Enter hace un salto de línea.
    if (evento.key === 'Enter' && !evento.shiftKey) {
        evento.preventDefault();
        void mandar();
    }
}

watch(
    () => [
        entradas.value.length,
        mensajes.value.at(-1)?.contenido,
        props.reunion?.estado,
        props.acta,
        props.tramos.filter((tramo) => tramo.resumen).length,
    ],
    () => bajar(),
);

// Al cambiar de reunión o pasar al chat general se arranca desde abajo.
watch(
    () => props.reunionId,
    () => {
        voz.detener();
        void bajar(true);
    },
);

// Al empezar o reanudar la grabación se calla, para no quedar grabado.
watch(
    () => props.escuchando,
    (grabando) => grabando && voz.detener(),
);
</script>

<template>
    <div
        class="relative flex min-h-0 flex-1 flex-col gap-3"
        @dragover.prevent="arrastrando = !enviando"
        @dragleave="alSalirArrastre"
        @drop.prevent="alSoltar"
    >
        <div
            v-if="arrastrando"
            class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-xl border-2 border-dashed border-cyan-400/70 bg-slate-950/80 text-sm text-cyan-100"
        >
            Suelta el documento para adjuntarlo
        </div>

        <div class="flex items-center justify-between">
            <p class="text-xs text-cyan-200/50">
                <template v-if="reunionId && reunionAbierta">
                    Chat de la reunión en curso: aquí se resume cada minuto.
                </template>
                <template v-else-if="reunionId">
                    Chat de la reunión terminada. Puedes seguir preguntando
                    sobre ella.
                </template>
                <template v-else>
                    Jarvis puede buscar en internet, leer páginas, ver YouTube,
                    consultar las reuniones y resumir documentos.
                </template>
            </p>
            <div class="flex shrink-0 items-center gap-1">
                <Button
                    size="sm"
                    variant="ghost"
                    class="text-cyan-200 hover:bg-cyan-950 hover:text-cyan-50"
                    :class="
                        voz.automatica.value
                            ? 'text-cyan-300'
                            : 'text-cyan-200/50'
                    "
                    :aria-pressed="voz.automatica.value"
                    :title="
                        voz.automatica.value
                            ? 'Jarvis lee sus respuestas en voz alta (no mientras se graba). Clic para desactivar.'
                            : 'Activar la lectura en voz alta de las respuestas'
                    "
                    @click="voz.alternarAutomatica"
                >
                    <Volume2 v-if="voz.automatica.value" />
                    <VolumeX v-else />
                    Voz
                </Button>
                <Button
                    v-if="
                        chatDeReunionTerminada ||
                        (mensajes.length && !reunionId)
                    "
                    size="sm"
                    variant="ghost"
                    class="text-cyan-200 hover:bg-cyan-950 hover:text-cyan-50"
                    :disabled="enviando"
                    @click="alPulsarNueva"
                >
                    <Plus /> Nueva
                </Button>
            </div>
        </div>

        <div
            ref="lista"
            class="flex min-h-[16rem] flex-1 flex-col gap-3 overflow-y-auto pr-1 lg:max-h-[60vh]"
            aria-live="polite"
            @scroll="alDesplazar"
        >
            <div
                v-if="!entradas.length"
                class="flex flex-1 flex-col justify-center gap-2"
            >
                <p
                    v-if="reunionId && reunionAbierta"
                    class="mb-2 text-sm text-cyan-200/60"
                >
                    Cada minuto grabado aparecerá aquí con su tema y un resumen
                    corto. Mientras tanto, puedes preguntarle a Jarvis.
                </p>
                <p class="text-sm text-cyan-200/60">Prueba con:</p>
                <button
                    v-for="sugerencia in sugerencias"
                    :key="sugerencia"
                    type="button"
                    class="rounded-lg border border-cyan-500/20 bg-cyan-950/20 px-3 py-2 text-left text-sm text-cyan-100/80 transition-colors hover:border-cyan-400/50 hover:bg-cyan-950/50"
                    @click="mandar(sugerencia)"
                >
                    {{ sugerencia }}
                </button>
                <button
                    type="button"
                    class="flex items-center gap-2 rounded-lg border border-dashed border-cyan-500/30 px-3 py-2 text-left text-sm text-cyan-100/70 transition-colors hover:border-cyan-400/50 hover:bg-cyan-950/50"
                    @click="selectorArchivo?.click()"
                >
                    <Paperclip class="size-4 shrink-0" />
                    Adjunta un documento ({{ nombresFormatos }}) para resumirlo
                </button>
            </div>

            <template v-for="entrada in entradas" :key="entrada.clave">
                <div
                    v-if="entrada.tipo === 'mensaje'"
                    class="flex"
                    :class="
                        entrada.mensaje.rol === 'usuario' ? 'justify-end' : ''
                    "
                >
                    <div
                        v-if="entrada.mensaje.rol === 'usuario'"
                        class="flex max-w-[85%] flex-col gap-1.5 rounded-2xl rounded-br-sm bg-cyan-400/90 px-3 py-2 text-sm text-slate-950"
                    >
                        <span
                            v-if="entrada.mensaje.documento"
                            class="flex items-center gap-1.5 rounded-md bg-slate-950/10 px-2 py-1 text-xs font-medium"
                        >
                            <FileText class="size-3.5 shrink-0" />
                            <span class="truncate">{{
                                entrada.mensaje.documento
                            }}</span>
                        </span>
                        <p class="whitespace-pre-wrap">
                            {{ entrada.mensaje.contenido }}
                        </p>
                    </div>
                    <div
                        v-else
                        class="max-w-[95%] rounded-2xl rounded-bl-sm border border-cyan-500/20 bg-cyan-950/30 px-3 py-2 text-sm"
                    >
                        <div
                            v-if="entrada.mensaje.contenido"
                            class="respuesta-jarvis flex flex-col gap-2 leading-relaxed break-words text-cyan-50/90"
                            v-html="markdownSeguro(entrada.mensaje.contenido)"
                        />
                        <p
                            v-if="entrada.mensaje.actividad"
                            class="flex items-center gap-2 text-xs text-cyan-300"
                            :class="entrada.mensaje.contenido ? 'mt-2' : ''"
                        >
                            <LoaderCircle class="size-3.5 animate-spin" />
                            {{ entrada.mensaje.actividad }}
                        </p>
                        <div
                            v-else-if="entrada.mensaje.contenido"
                            class="mt-1 flex justify-end"
                        >
                            <button
                                type="button"
                                class="flex items-center gap-1 rounded px-1.5 py-0.5 text-xs text-cyan-300/60 transition-colors hover:bg-cyan-900/60 hover:text-cyan-100"
                                :aria-label="
                                    voz.activa.value === entrada.clave
                                        ? 'Detener lectura'
                                        : 'Escuchar respuesta'
                                "
                                @click="
                                    voz.alternar(
                                        entrada.clave,
                                        entrada.mensaje.contenido,
                                    )
                                "
                            >
                                <template
                                    v-if="voz.activa.value === entrada.clave"
                                >
                                    <LoaderCircle
                                        v-if="voz.cargando.value"
                                        class="size-3.5 animate-spin"
                                    />
                                    <Square v-else class="size-3.5" />
                                    {{
                                        voz.cargando.value
                                            ? 'Preparando voz…'
                                            : 'Detener'
                                    }}
                                </template>
                                <template v-else>
                                    <Volume2 class="size-3.5" />
                                    Escuchar
                                </template>
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    v-else-if="entrada.tipo === 'tramo'"
                    class="rounded-xl border border-dashed border-cyan-500/25 bg-slate-950/40 px-3 py-2 text-sm"
                >
                    <p class="font-mono text-[11px] text-cyan-400/60">
                        Minuto {{ rango(entrada.tramo) }}
                    </p>
                    <template v-if="entrada.tramo.resumen">
                        <p class="font-medium text-cyan-50">
                            {{ entrada.tramo.resumen.tema }}
                        </p>
                        <p class="text-cyan-100/70">
                            {{ entrada.tramo.resumen.resumen }}
                        </p>
                        <ul
                            v-if="entrada.tramo.resumen.puntos.length"
                            class="mt-1 list-disc pl-5 text-xs text-cyan-100/60"
                        >
                            <li
                                v-for="punto in entrada.tramo.resumen.puntos"
                                :key="punto"
                            >
                                {{ punto }}
                            </li>
                        </ul>
                    </template>
                    <p
                        v-else-if="entrada.tramo.estado === 'error'"
                        class="text-xs text-rose-300"
                        :title="entrada.tramo.error ?? undefined"
                    >
                        No se pudo transcribir este minuto.
                    </p>
                    <p
                        v-else
                        class="flex items-center gap-2 text-xs text-cyan-300/70"
                    >
                        <LoaderCircle class="size-3 animate-spin" />
                        {{
                            entrada.tramo.estado === 'listo'
                                ? 'Resumiendo…'
                                : 'Transcribiendo…'
                        }}
                    </p>
                </div>

                <div
                    v-else-if="entrada.tipo === 'acta' && reunion"
                    class="flex flex-col gap-2 rounded-xl border border-emerald-400/30 bg-emerald-950/20 px-3 py-3 text-sm"
                >
                    <p
                        class="flex items-center gap-2 font-mono text-xs tracking-[0.2em] text-emerald-300 uppercase"
                    >
                        <ClipboardList class="size-4" />
                        Acta de la reunión
                    </p>

                    <p
                        v-if="reunion.estado === 'procesando'"
                        class="flex items-center gap-2 text-cyan-100/70"
                    >
                        <LoaderCircle class="size-4 animate-spin" />
                        Jarvis está preparando el acta…
                    </p>
                    <p
                        v-else-if="reunion.estado === 'error' || !acta"
                        class="text-rose-300"
                    >
                        No se pudo generar el acta. Revisa la pestaña Reunión
                        para reintentarlo.
                    </p>
                    <template v-else>
                        <p class="font-medium text-cyan-50">
                            {{ reunion.titulo || acta.titulo_sugerido }}
                        </p>
                        <p class="leading-relaxed text-cyan-50/90">
                            {{ acta.resumen_ejecutivo }}
                        </p>
                        <ul
                            v-if="acta.temas_tratados.length"
                            class="flex flex-wrap gap-1.5"
                        >
                            <li
                                v-for="tema in acta.temas_tratados"
                                :key="tema"
                                class="rounded-full border border-cyan-500/30 px-2.5 py-0.5 text-xs text-cyan-100/80"
                            >
                                {{ tema }}
                            </li>
                        </ul>
                        <div v-if="acta.acuerdos.length">
                            <p class="font-medium text-cyan-50">Acuerdos</p>
                            <ul class="list-disc pl-5 text-cyan-100/80">
                                <li
                                    v-for="acuerdo in acta.acuerdos"
                                    :key="acuerdo.descripcion"
                                >
                                    {{ acuerdo.descripcion }}
                                    <span
                                        v-if="
                                            acuerdo.responsable ||
                                            acuerdo.fecha_limite
                                        "
                                        class="text-xs text-cyan-200/50"
                                    >
                                        ·
                                        {{
                                            [
                                                acuerdo.responsable,
                                                acuerdo.fecha_limite,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')
                                        }}
                                    </span>
                                </li>
                            </ul>
                        </div>
                        <div v-if="acta.pendientes.length">
                            <p class="font-medium text-cyan-50">Pendientes</p>
                            <ul class="list-disc pl-5 text-cyan-100/80">
                                <li
                                    v-for="pendiente in acta.pendientes"
                                    :key="pendiente"
                                >
                                    {{ pendiente }}
                                </li>
                            </ul>
                        </div>
                        <Link
                            :href="verReunion(reunion.id)"
                            class="flex items-center gap-1.5 self-start text-xs text-cyan-300 underline underline-offset-2 hover:text-cyan-100"
                        >
                            <FileText class="size-3.5" />
                            Ver acta completa
                        </Link>
                    </template>
                </div>
            </template>

            <p
                v-if="reunionAbierta && escuchando && tramos.length"
                class="text-xs text-cyan-200/40"
            >
                Minuto en curso… se resumirá al completarse.
            </p>
        </div>

        <p
            v-if="error || voz.error.value || dictado.error.value"
            class="flex items-center gap-2 text-sm text-rose-300"
            role="alert"
        >
            <CircleAlert class="size-4 shrink-0" />
            {{ error || voz.error.value || dictado.error.value }}
        </p>

        <div
            v-if="documento"
            class="flex items-center gap-2 rounded-lg border border-cyan-500/30 bg-cyan-950/40 px-3 py-2 text-sm text-cyan-100"
        >
            <FileText class="size-4 shrink-0 text-cyan-300" />
            <span class="min-w-0 flex-1 truncate">{{ documento.name }}</span>
            <span class="shrink-0 text-xs text-cyan-200/50">
                {{ formatearTamano(documento.size) }}
            </span>
            <button
                type="button"
                class="shrink-0 rounded p-0.5 text-cyan-200/60 hover:bg-cyan-900 hover:text-cyan-50"
                aria-label="Quitar documento"
                :disabled="enviando"
                @click="documento = null"
            >
                <X class="size-4" />
            </button>
        </div>

        <form class="flex items-end gap-2" @submit.prevent="alEnviarFormulario">
            <input
                ref="selectorArchivo"
                type="file"
                class="hidden"
                :accept="formatosAceptados"
                @change="alElegirArchivo"
            />
            <Button
                type="button"
                size="icon"
                variant="ghost"
                :disabled="enviando"
                class="text-cyan-200 hover:bg-cyan-950 hover:text-cyan-50"
                aria-label="Adjuntar documento"
                :title="`Adjuntar documento (${nombresFormatos})`"
                @click="selectorArchivo?.click()"
            >
                <Paperclip />
            </Button>
            <Button
                v-if="dictado.estado.value === 'inactivo'"
                type="button"
                size="icon"
                variant="ghost"
                :disabled="enviando"
                class="text-cyan-200 hover:bg-cyan-950 hover:text-cyan-50"
                aria-label="Dictar mensaje"
                :title="
                    escuchando
                        ? 'Dictar mensaje (lo que digas también quedará en la grabación de la reunión)'
                        : 'Dictar mensaje'
                "
                @click="dictar"
            >
                <Mic />
            </Button>

            <div
                v-if="dictado.estado.value !== 'inactivo'"
                class="flex min-h-[2.75rem] flex-1 items-center gap-3 rounded-lg border border-rose-400/40 bg-rose-950/20 px-3 py-2 text-sm text-cyan-50"
                role="status"
            >
                <template v-if="dictado.estado.value === 'grabando'">
                    <span class="relative flex size-2.5 shrink-0">
                        <span
                            class="absolute inline-flex size-full animate-ping rounded-full bg-rose-400 opacity-75"
                        />
                        <span
                            class="relative inline-flex size-2.5 rounded-full bg-rose-500"
                        />
                    </span>
                    <span class="flex-1">
                        Te escucho…
                        <span class="font-mono text-xs text-cyan-200/60">
                            {{ formatearTiempo(dictado.segundos.value) }}
                        </span>
                        <span
                            class="ml-2 hidden text-xs text-cyan-200/40 sm:inline"
                        >
                            Enter para enviar
                        </span>
                    </span>
                    <button
                        type="button"
                        class="rounded p-1 text-cyan-200/60 hover:bg-cyan-900 hover:text-cyan-50"
                        aria-label="Cancelar dictado"
                        title="Cancelar (Esc)"
                        @click="dictado.cancelar"
                    >
                        <X class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="rounded bg-cyan-400 p-1 text-slate-950 hover:bg-cyan-300"
                        aria-label="Terminar dictado"
                        title="Pasar a texto sin enviar, para revisarlo"
                        @click="dictado.terminar"
                    >
                        <Check class="size-4" />
                    </button>
                </template>
                <template v-else>
                    <LoaderCircle
                        class="size-4 shrink-0 animate-spin text-cyan-300"
                    />
                    Pasando a texto…
                </template>
            </div>

            <textarea
                v-else
                ref="cajaTexto"
                v-model="texto"
                rows="2"
                maxlength="4000"
                :placeholder="
                    documento
                        ? `Pregunta algo del documento o envía para: “${PEDIDO_RESUMEN_DOCUMENTO}”`
                        : 'Escríbele a Jarvis…'
                "
                aria-label="Mensaje para Jarvis"
                class="min-h-[2.75rem] flex-1 resize-none rounded-lg border border-cyan-500/30 bg-cyan-950/30 px-3 py-2 text-sm text-cyan-50 placeholder:text-cyan-200/40 focus:border-cyan-400 focus:outline-none"
                @keydown="alPresionarTecla"
            />
            <Button
                type="submit"
                size="icon"
                :disabled="
                    enviando ||
                    dictado.estado.value === 'transcribiendo' ||
                    (dictado.estado.value === 'inactivo' &&
                        !texto.trim() &&
                        !documento)
                "
                class="bg-cyan-400 text-slate-950 hover:bg-cyan-300"
                :title="
                    dictado.estado.value === 'grabando'
                        ? 'Terminar y enviar (Enter)'
                        : 'Enviar'
                "
                aria-label="Enviar"
            >
                <SendHorizontal />
            </Button>
        </form>
    </div>
</template>

<style scoped>
.respuesta-jarvis :deep(a) {
    color: rgb(103 232 249);
    text-decoration: underline;
    text-underline-offset: 2px;
    word-break: break-all;
}

.respuesta-jarvis :deep(ul) {
    list-style: disc;
    padding-left: 1.25rem;
}

.respuesta-jarvis :deep(ol) {
    list-style: decimal;
    padding-left: 1.25rem;
}

.respuesta-jarvis :deep(li + li) {
    margin-top: 0.35rem;
}
</style>
