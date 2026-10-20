<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    CircleAlert,
    ClipboardList,
    FileText,
    AudioLines,
    Check,
    ChevronDown,
    LoaderCircle,
    Mic,
    MicOff,
    Paperclip,
    Plus,
    SendHorizontal,
    Square,
    Volume2,
    VolumeX,
    X,
} from '@lucide/vue';
import { useEventListener, useObjectUrl } from '@vueuse/core';
import { computed, nextTick, ref, toRef, watch } from 'vue';
import { show as verReunion } from '@/actions/App/Http/Controllers/HistorialController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { esImagen, esSoloDocumento, useChat } from '@/composables/useChat';
import type { MensajeChat } from '@/composables/useChat';
import { useConversacionEnVivo } from '@/composables/useConversacionEnVivo';
import { useDictado } from '@/composables/useDictado';
import { useVoz } from '@/composables/useVoz';
import { formatearTamano, formatearTiempo } from '@/lib/formato';
import { markdownSeguro } from '@/lib/markdown';
import type {
    ConfigDocumentos,
    ConfigVoces,
    ContenidoResumen,
    Reunion,
} from '@/types';

const props = withDefaults(
    defineProps<{
        // Reunión a la que pertenece el chat (abierta o recién terminada); null = chat general.
        reunionId: string | null;
        reunionAbierta?: boolean;
        // Estado de esa reunión según el servidor (para el acta).
        reunion?: Reunion | null;
        acta?: ContenidoResumen | null;
        escuchando?: boolean;
        documentos: ConfigDocumentos;
        // Duración máxima de un dictado por micrófono.
        dictadoMaxSegundos?: number;
        voces: ConfigVoces;
        // Se espera antes de abrir el micrófono de la voz en vivo (pausar la reunión que se graba).
        prepararEnVivo?: () => Promise<void>;
        // Al silenciar la voz, la reunión puede seguir grabando.
        reanudarEnSilencio?: () => Promise<void>;
    }>(),
    {
        reunionAbierta: false,
        reunion: null,
        acta: null,
        escuchando: false,
        dictadoMaxSegundos: 120,
    },
);

const emit = defineEmits<{
    // Pide dejar el chat de la reunión terminada y pasar al chat general.
    salirDeReunion: [];
    // Avisa al empezar y al terminar la conversación por voz en vivo (para pausar la reunión).
    enVivo: [activo: boolean];
}>();

const { mensajes, conversacionId, enviando, error, nueva, enviar, recargar } =
    useChat(toRef(props, 'reunionId'));

const voz = useVoz(
    props.voces.porDefecto,
    props.voces.lista.map((opcion) => opcion.id),
);
const dictado = useDictado(props.dictadoMaxSegundos);

// Conversación por voz en vivo: al guardarse, el chat pasa a esa conversación.
const enVivo = useConversacionEnVivo(async (id) => {
    if (!props.reunionId) {
        conversacionId.value = id;
    }

    await recargar(id);
});

const conversandoEnVivo = computed(() => enVivo.estado.value !== 'apagado');

const textoEnVivo = computed(() =>
    enviando.value && enVivo.estado.value === 'escuchando'
        ? 'Respondiendo por escrito… en unos segundos te lo comento.'
        : enVivo.silenciado.value && enVivo.estado.value === 'escuchando'
          ? 'Micrófono silenciado: actívalo para hablar.'
          : {
                apagado: '',
                conectando: 'Conectando…',
                escuchando: 'Te escucho. Habla cuando quieras.',
                pensando: enVivo.actividad.value ?? 'Pensando…',
                hablando: enVivo.interrumpible.value
                    ? 'Respondiendo… (puedes interrumpirlo hablando)'
                    : 'Respondiendo… (te escucho cuando termine)',
                guardando: 'Guardando la conversación en el chat…',
            }[enVivo.estado.value],
);

async function hablarEnVivo() {
    voz.detener();
    error.value = null;
    pegadoAlFinal.value = true;
    await props.prepararEnVivo?.();
    await enVivo.iniciar({
        voz: voz.voz.value,
        reunionId: props.reunionId,
        conversacionId: () => conversacionId.value,
        alCrearConversacion: (id) => {
            if (!props.reunionId) {
                conversacionId.value = id;
            }
        },
    });
}

watch(conversandoEnVivo, (activo) => emit('enVivo', activo));

// Silenciada, la voz suelta el micrófono y la reunión retoma la grabación; al
// activarla, primero se pausa la grabación para que la voz no se escuche a sí misma.
async function alternarSilencioEnVivo() {
    if (enVivo.silenciado.value) {
        await props.prepararEnVivo?.();
        await enVivo.alternarSilencio();

        return;
    }

    await enVivo.alternarSilencio();
    await props.reanudarEnSilencio?.();
}
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
// Miniatura de la imagen a punto de enviarse.
const vistaPrevia = useObjectUrl(
    computed(() =>
        documento.value && esImagen(documento.value)
            ? documento.value
            : undefined,
    ),
);
const arrastrando = ref(false);
const lista = ref<HTMLElement | null>(null);
const selectorArchivo = ref<HTMLInputElement | null>(null);
// Solo se baja sola la lista si el usuario no subió a leer algo anterior.
const pegadoAlFinal = ref(true);

type Entrada =
    | { tipo: 'mensaje'; clave: string; hora: number; mensaje: MensajeChat }
    | { tipo: 'acta'; clave: string; hora: number };

const hora = (iso: string | null | undefined) =>
    iso ? Date.parse(iso) : Number.MAX_SAFE_INTEGER;

// Mensajes que el chat ya trae guardados (la voz se guarda por partes).
const guardadosEnChat = computed(
    () => new Set(mensajes.value.map((mensaje) => mensaje.id)),
);

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
        // Lo que se va diciendo en la conversación en vivo. Se guarda por
        // partes; si el chat ya lo trae guardado, no se repite.
        ...enVivo.mensajes.value
            .filter((dicho) => !guardadosEnChat.value.has(dicho.uuid))
            .map((dicho) => ({
                tipo: 'mensaje' as const,
                clave: `v${dicho.id}`,
                hora: hora(dicho.creado_en),
                mensaje: {
                    rol: dicho.rol,
                    contenido: dicho.contenido || '…',
                    creado_en: dicho.creado_en,
                },
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
        error.value = `El archivo pesa ${formatearTamano(archivo.size)} y el máximo es ${formatearTamano(props.documentos.maxKb * 1024)}.`;

        return;
    }

    error.value = null;

    documento.value = archivo;
}

// Una captura copiada (Ctrl+V) se adjunta como imagen; el texto se pega normal.
function alPegar(evento: ClipboardEvent) {
    const archivo = Array.from(evento.clipboardData?.files ?? []).find(
        esImagen,
    );

    if (!archivo) {
        return;
    }

    evento.preventDefault();
    const extension =
        archivo.type.split('/')[1]?.replace('jpeg', 'jpg') ?? 'png';
    const hora = new Date()
        .toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })
        .replace(':', '.');
    adjuntar(
        new File([archivo], `Captura ${hora}.${extension}`, {
            type: archivo.type,
        }),
    );
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

    if (conversandoEnVivo.value) {
        enVivo.avisarEnvioEscrito(mensaje.trim(), archivo?.name ?? null);
    }

    // Si no llegó a Jarvis, se devuelve lo que el usuario había preparado.
    if (!(await enviar(mensaje, archivo))) {
        texto.value = escrito;
        documento.value = archivo;

        return;
    }

    // Con la voz en vivo, la respuesta escrita se le pasa a la voz para que la comente.
    if (conversandoEnVivo.value) {
        const respuesta = mensajes.value.at(-1);

        if (respuesta?.rol === 'jarvis' && respuesta.contenido.trim()) {
            enVivo.contarMensajeEscrito(
                mensaje.trim(),
                archivo?.name ?? null,
                respuesta.contenido,
            );
        }

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
        enVivo.mensajes.value.at(-1)?.contenido,
        props.reunion?.estado,
        props.acta,
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
            class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-xl border-2 border-dashed border-hud-acento/70 bg-hud-fondo/90 text-sm text-hud-texto"
        >
            Suelta el documento o la imagen para adjuntarlo
        </div>

        <div class="flex items-center justify-between">
            <p class="text-xs text-hud-suave">
                <template v-if="reunionId && reunionAbierta">
                    Chat de la reunión en curso: conoce lo que se va diciendo.
                </template>
                <template v-else-if="reunionId">
                    Chat de la reunión terminada. Puedes seguir preguntando
                    sobre ella.
                </template>
                <template v-else>
                    El Asistente IA puede buscar en internet, leer páginas, ver
                    YouTube, consultar las reuniones y resumir documentos.
                </template>
            </p>
            <div class="flex shrink-0 items-center gap-1">
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            size="sm"
                            variant="ghost"
                            class="hover:bg-hud-superficie hover:text-hud-texto"
                            :class="
                                voz.automatica.value
                                    ? 'text-hud-acento-texto'
                                    : 'text-hud-suave'
                            "
                            title="Voz del asistente"
                        >
                            <Volume2 v-if="voz.automatica.value" />
                            <VolumeX v-else />
                            Voz
                            <ChevronDown class="size-3.5 opacity-60" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-64">
                        <DropdownMenuCheckboxItem
                            :model-value="voz.automatica.value"
                            @update:model-value="voz.alternarAutomatica"
                            @select.prevent
                        >
                            Leer respuestas en voz alta
                        </DropdownMenuCheckboxItem>
                        <p class="px-2 pb-1 text-xs text-hud-suave">
                            No lee solo mientras se graba una reunión.
                        </p>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel>
                            Voz (al elegir, suena una muestra)
                        </DropdownMenuLabel>
                        <DropdownMenuRadioGroup
                            :model-value="voz.voz.value"
                            @update:model-value="
                                (id) => voz.elegirVoz(String(id))
                            "
                        >
                            <DropdownMenuRadioItem
                                v-for="opcion in voces.lista"
                                :key="opcion.id"
                                :value="opcion.id"
                                @select.prevent
                            >
                                <span class="capitalize">{{ opcion.id }}</span>
                                <span class="ml-auto text-xs text-hud-suave">
                                    {{ opcion.descripcion }}
                                </span>
                            </DropdownMenuRadioItem>
                        </DropdownMenuRadioGroup>
                    </DropdownMenuContent>
                </DropdownMenu>
                <Button
                    v-if="
                        chatDeReunionTerminada ||
                        (mensajes.length && !reunionId)
                    "
                    size="sm"
                    variant="ghost"
                    class="text-hud-suave hover:bg-hud-superficie hover:text-hud-texto"
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
                    class="mb-2 text-sm text-hud-suave"
                >
                    Cada minuto grabado aparecerá aquí con su tema y un resumen
                    corto. Mientras tanto, puedes preguntarle al Asistente IA.
                </p>
                <p class="text-sm text-hud-suave">Prueba con:</p>
                <button
                    v-for="sugerencia in sugerencias"
                    :key="sugerencia"
                    type="button"
                    class="rounded-lg border border-hud-acento/20 bg-hud-superficie/20 px-3 py-2 text-left text-sm text-hud-texto/80 transition-colors hover:border-hud-acento/50 hover:bg-hud-superficie/50"
                    @click="mandar(sugerencia)"
                >
                    {{ sugerencia }}
                </button>
                <button
                    type="button"
                    class="flex items-center gap-2 rounded-lg border border-dashed border-hud-acento/30 px-3 py-2 text-left text-sm text-hud-texto/70 transition-colors hover:border-hud-acento/50 hover:bg-hud-superficie/50"
                    @click="selectorArchivo?.click()"
                >
                    <Paperclip class="size-4 shrink-0" />
                    Adjunta un documento o una imagen ({{ nombresFormatos }})
                    para analizarlo
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
                        class="flex max-w-[85%] flex-col gap-1.5 rounded-2xl rounded-br-sm bg-hud-acento/90 px-3 py-2 text-sm text-hud-sobre-acento"
                    >
                        <a
                            v-if="entrada.mensaje.imagen"
                            :href="entrada.mensaje.imagen"
                            target="_blank"
                            rel="noopener"
                            :title="entrada.mensaje.documento ?? undefined"
                        >
                            <img
                                :src="entrada.mensaje.imagen"
                                :alt="
                                    entrada.mensaje.documento ??
                                    'Imagen adjunta'
                                "
                                class="max-h-60 rounded-lg bg-white/10 object-contain"
                            />
                        </a>
                        <span
                            v-else-if="entrada.mensaje.documento"
                            class="flex items-center gap-1.5 rounded-md bg-white/20 px-2 py-1 text-xs font-medium"
                        >
                            <FileText class="size-3.5 shrink-0" />
                            <span class="truncate">{{
                                entrada.mensaje.documento
                            }}</span>
                        </span>
                        <!-- Un documento enviado sin escribir nada se ve solo con su nombre. -->
                        <p
                            v-if="!esSoloDocumento(entrada.mensaje)"
                            class="whitespace-pre-wrap"
                        >
                            {{ entrada.mensaje.contenido }}
                        </p>
                    </div>
                    <div
                        v-else
                        class="max-w-[95%] rounded-2xl rounded-bl-sm border border-hud-acento/20 bg-hud-superficie/30 px-3 py-2 text-sm"
                    >
                        <div
                            v-if="entrada.mensaje.contenido"
                            class="respuesta-jarvis flex flex-col gap-2 leading-relaxed break-words text-hud-texto/90"
                            v-html="markdownSeguro(entrada.mensaje.contenido)"
                        />
                        <p
                            v-if="entrada.mensaje.actividad"
                            class="flex items-center gap-2 text-xs text-hud-acento-texto"
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
                                class="flex items-center gap-1 rounded px-1.5 py-0.5 text-xs text-hud-acento-texto/60 transition-colors hover:bg-hud-superficie/60 hover:text-hud-texto"
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
                    v-else-if="entrada.tipo === 'acta' && reunion"
                    class="flex flex-col gap-2 rounded-xl border border-emerald-300 bg-emerald-50 px-3 py-3 text-sm"
                >
                    <p
                        class="flex items-center gap-2 font-mono text-xs tracking-[0.2em] text-emerald-600 uppercase"
                    >
                        <ClipboardList class="size-4" />
                        Acta de la reunión
                    </p>

                    <p
                        v-if="reunion.estado === 'procesando'"
                        class="flex items-center gap-2 text-hud-texto/70"
                    >
                        <LoaderCircle class="size-4 animate-spin" />
                        El Asistente IA está preparando el acta…
                    </p>
                    <p
                        v-else-if="reunion.estado === 'error' || !acta"
                        class="text-rose-600"
                    >
                        No se pudo generar el acta. Revisa la pestaña Reunión
                        para reintentarlo.
                    </p>
                    <template v-else>
                        <p class="font-medium text-hud-texto">
                            {{ reunion.titulo || acta.titulo_sugerido }}
                        </p>
                        <p class="leading-relaxed text-hud-texto/90">
                            {{ acta.resumen_ejecutivo }}
                        </p>
                        <ul
                            v-if="acta.temas_tratados.length"
                            class="flex flex-wrap gap-1.5"
                        >
                            <li
                                v-for="tema in acta.temas_tratados"
                                :key="tema"
                                class="rounded-full border border-hud-acento/30 px-2.5 py-0.5 text-xs text-hud-texto/80"
                            >
                                {{ tema }}
                            </li>
                        </ul>
                        <div v-if="acta.acuerdos.length">
                            <p class="font-medium text-hud-texto">Acuerdos</p>
                            <ul class="list-disc pl-5 text-hud-texto/80">
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
                                        class="text-xs text-hud-suave"
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
                            <p class="font-medium text-hud-texto">Pendientes</p>
                            <ul class="list-disc pl-5 text-hud-texto/80">
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
                            class="flex items-center gap-1.5 self-start text-xs text-hud-acento-texto underline underline-offset-2 hover:text-hud-texto"
                        >
                            <FileText class="size-3.5" />
                            Ver acta completa
                        </Link>
                    </template>
                </div>
            </template>
        </div>

        <p
            v-if="
                error ||
                voz.error.value ||
                dictado.error.value ||
                enVivo.error.value
            "
            class="flex items-center gap-2 text-sm text-rose-600"
            role="alert"
        >
            <CircleAlert class="size-4 shrink-0" />
            {{
                error ||
                voz.error.value ||
                dictado.error.value ||
                enVivo.error.value
            }}
        </p>

        <input
            ref="selectorArchivo"
            type="file"
            class="hidden"
            :accept="formatosAceptados"
            @change="alElegirArchivo"
        />

        <!-- Una sola caja: la voz en vivo, el documento, el texto y las acciones. -->
        <form
            class="flex flex-col rounded-2xl border bg-hud-superficie/30 transition-[border-color,box-shadow] focus-within:border-hud-acento"
            :class="
                conversandoEnVivo
                    ? 'border-hud-acento/60 shadow-[0_0_0_3px_rgba(var(--hud-acento-rgb),0.12)]'
                    : 'border-hud-acento/30'
            "
            @submit.prevent="alEnviarFormulario"
        >
            <div
                v-if="conversandoEnVivo"
                class="flex items-center gap-3 rounded-t-2xl border-b border-hud-acento/20 bg-hud-acento/5 px-3 py-2"
                role="status"
            >
                <span
                    class="flex h-5 shrink-0 items-center gap-[3px]"
                    aria-hidden="true"
                >
                    <span
                        v-for="barra in 5"
                        :key="barra"
                        class="onda w-[3px] rounded-full bg-hud-acento"
                        :class="{
                            'onda-activa':
                                enVivo.estado.value === 'hablando' ||
                                (enVivo.estado.value === 'escuchando' &&
                                    !enVivo.silenciado.value),
                        }"
                        :style="{ animationDelay: `${barra * 0.12}s` }"
                    />
                </span>
                <div class="min-w-0 flex-1">
                    <p
                        class="text-[11px] font-semibold tracking-[0.15em] text-hud-acento-texto uppercase"
                    >
                        En vivo
                    </p>
                    <p
                        class="flex items-center gap-1.5 truncate text-xs text-hud-suave"
                    >
                        <LoaderCircle
                            v-if="
                                enviando ||
                                [
                                    'conectando',
                                    'pensando',
                                    'guardando',
                                ].includes(enVivo.estado.value)
                            "
                            class="size-3 shrink-0 animate-spin"
                        />
                        {{ textoEnVivo }}
                    </p>
                </div>
                <button
                    type="button"
                    class="flex size-7 shrink-0 items-center justify-center rounded-full border transition-colors disabled:opacity-50"
                    :class="
                        enVivo.silenciado.value
                            ? 'border-rose-300 bg-rose-50 text-rose-600 hover:bg-rose-100'
                            : 'border-hud-acento/40 bg-white/60 text-hud-acento-texto hover:bg-hud-acento/10'
                    "
                    :disabled="
                        ['conectando', 'guardando'].includes(
                            enVivo.estado.value,
                        )
                    "
                    :aria-label="
                        enVivo.silenciado.value
                            ? 'Activar micrófono'
                            : 'Silenciar micrófono'
                    "
                    :aria-pressed="enVivo.silenciado.value"
                    :title="
                        enVivo.silenciado.value
                            ? 'Activar micrófono'
                            : 'Silenciar micrófono (la conversación sigue abierta y la reunión vuelve a grabar)'
                    "
                    @click="alternarSilencioEnVivo"
                >
                    <MicOff v-if="enVivo.silenciado.value" class="size-3.5" />
                    <Mic v-else class="size-3.5" />
                </button>
                <button
                    type="button"
                    class="flex shrink-0 items-center gap-1.5 rounded-full border border-rose-300 bg-white/60 px-3 py-1 text-xs font-medium text-rose-600 transition-colors hover:bg-rose-50 disabled:opacity-50"
                    :disabled="enVivo.estado.value === 'guardando'"
                    @click="enVivo.terminar"
                >
                    <Square class="size-3 fill-current" />
                    Terminar
                </button>
            </div>

            <div v-if="documento" class="px-3 pt-3">
                <div
                    class="inline-flex max-w-full items-center gap-2 rounded-lg border border-hud-acento/30 bg-hud-fondo/70 px-2.5 py-1.5 text-sm text-hud-texto"
                >
                    <img
                        v-if="vistaPrevia"
                        :src="vistaPrevia"
                        alt=""
                        class="size-10 shrink-0 rounded object-cover"
                    />
                    <FileText
                        v-else
                        class="size-4 shrink-0 text-hud-acento-texto"
                    />
                    <span class="min-w-0 truncate">{{ documento.name }}</span>
                    <span class="shrink-0 text-xs text-hud-suave">
                        {{ formatearTamano(documento.size) }}
                    </span>
                    <button
                        type="button"
                        class="shrink-0 rounded p-0.5 text-hud-suave hover:bg-hud-superficie hover:text-hud-texto"
                        aria-label="Quitar adjunto"
                        :disabled="enviando"
                        @click="documento = null"
                    >
                        <X class="size-3.5" />
                    </button>
                </div>
            </div>

            <div
                v-if="dictado.estado.value !== 'inactivo'"
                class="m-2 flex min-h-[2.75rem] items-center gap-3 rounded-xl bg-rose-50 px-3 py-2 text-sm text-hud-texto"
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
                        <span class="font-mono text-xs text-hud-suave">
                            {{ formatearTiempo(dictado.segundos.value) }}
                        </span>
                        <span
                            class="ml-2 hidden text-xs text-hud-suave sm:inline"
                        >
                            Enter para enviar
                        </span>
                    </span>
                    <button
                        type="button"
                        class="rounded p-1 text-hud-suave hover:bg-hud-superficie hover:text-hud-texto"
                        aria-label="Cancelar dictado"
                        title="Cancelar (Esc)"
                        @click="dictado.cancelar"
                    >
                        <X class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="rounded bg-hud-acento p-1 text-hud-sobre-acento hover:bg-hud-acento-fuerte"
                        aria-label="Terminar dictado"
                        title="Pasar a texto sin enviar, para revisarlo"
                        @click="dictado.terminar"
                    >
                        <Check class="size-4" />
                    </button>
                </template>
                <template v-else>
                    <LoaderCircle
                        class="size-4 shrink-0 animate-spin text-hud-acento-texto"
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
                        ? esImagen(documento)
                            ? 'Pregunta algo de la imagen, o envíala así'
                            : 'Pregunta algo del documento, o envíalo así'
                        : conversandoEnVivo
                          ? 'Escribe o adjunta algo mientras conversan…'
                          : 'Escríbele al Asistente IA…'
                "
                aria-label="Mensaje para el Asistente IA"
                class="min-h-[3.5rem] w-full resize-none bg-transparent px-3 pt-3 pb-1 text-sm text-hud-texto placeholder:text-hud-suave/70 focus:outline-none"
                @keydown="alPresionarTecla"
                @paste="alPegar"
            />

            <div class="flex items-center gap-1 px-2 pb-2">
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    :disabled="enviando"
                    class="size-8 rounded-full text-hud-suave hover:bg-hud-superficie hover:text-hud-texto"
                    aria-label="Adjuntar documento o imagen"
                    :title="`Adjuntar documento o imagen (${nombresFormatos})`"
                    @click="selectorArchivo?.click()"
                >
                    <Paperclip />
                </Button>
                <Button
                    v-if="
                        dictado.estado.value === 'inactivo' &&
                        !conversandoEnVivo
                    "
                    type="button"
                    size="icon"
                    variant="ghost"
                    :disabled="enviando"
                    class="size-8 rounded-full text-hud-suave hover:bg-hud-superficie hover:text-hud-texto"
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
                <button
                    v-if="
                        dictado.estado.value === 'inactivo' &&
                        !conversandoEnVivo
                    "
                    type="button"
                    :disabled="enviando"
                    class="flex h-8 items-center gap-1.5 rounded-full border border-hud-acento/40 px-3 text-xs font-medium text-hud-acento-texto transition-colors hover:bg-hud-acento/10 disabled:opacity-50"
                    :title="
                        escuchando
                            ? 'Conversar por voz en vivo (la reunión se pausa mientras tanto)'
                            : 'Conversar por voz en vivo: hablas y responde con voz'
                    "
                    @click="hablarEnVivo"
                >
                    <AudioLines class="size-4" />
                    En vivo
                </button>

                <span
                    class="ml-auto hidden pr-1 text-[11px] text-hud-suave/70 sm:inline"
                >
                    Enter para enviar · Shift + Enter, nueva línea
                </span>
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
                    class="ml-auto size-8 rounded-full bg-hud-acento text-hud-sobre-acento hover:bg-hud-acento-fuerte sm:ml-0"
                    :title="
                        dictado.estado.value === 'grabando'
                            ? 'Terminar y enviar (Enter)'
                            : 'Enviar'
                    "
                    aria-label="Enviar"
                >
                    <SendHorizontal />
                </Button>
            </div>
        </form>
    </div>
</template>

<style scoped>
/* Barras de la voz en vivo: se mueven mientras escucha o habla. */
.onda {
    height: 6px;
    opacity: 0.5;
}

.onda-activa {
    animation: onda 1s ease-in-out infinite;
    opacity: 1;
}

@keyframes onda {
    0%,
    100% {
        height: 5px;
    }

    50% {
        height: 18px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .onda-activa {
        animation: none;
        height: 12px;
    }
}

.respuesta-jarvis :deep(a) {
    color: var(--hud-acento-texto);
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
