<script setup lang="ts">
import {
    CircleAlert,
    FileText,
    LoaderCircle,
    Paperclip,
    Sparkles,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import GenerarAgendaController from '@/actions/App/Http/Controllers/GenerarAgendaController';
import { Button } from '@/components/ui/button';
import { esImagen } from '@/composables/useChat';
import { formatearTamano } from '@/lib/formato';
import { tokenXsrf } from '@/lib/xsrf';
import type { ConfigDocumentos } from '@/types';

const MAX_ARCHIVOS = 5;

const props = defineProps<{
    documentos: ConfigDocumentos;
    // Cuántos puntos tiene ya la agenda: decide si se ofrece "Agregar".
    puntosActuales: number;
}>();

const emit = defineEmits<{
    aplicar: [puntos: string[], modo: 'reemplazar' | 'agregar'];
    cerrar: [];
}>();

const archivos = ref<File[]>([]);
const texto = ref('');
const generando = ref(false);
const error = ref<string | null>(null);
const propuesta = ref<{ puntos: string[]; aviso: string | null } | null>(null);
const selector = ref<HTMLInputElement | null>(null);
const arrastrando = ref(false);

const formatosAceptados = computed(() =>
    props.documentos.formatos.map((formato) => `.${formato}`).join(','),
);

const listo = computed(
    () =>
        (archivos.value.length > 0 || texto.value.trim() !== '') &&
        !generando.value,
);

function agregarArchivos(nuevos: File[]) {
    error.value = null;

    for (const archivo of nuevos) {
        const extension = archivo.name.split('.').pop()?.toLowerCase() ?? '';

        if (archivos.value.length >= MAX_ARCHIVOS) {
            error.value = `Puedes adjuntar hasta ${MAX_ARCHIVOS} archivos.`;
            break;
        }

        if (!props.documentos.formatos.includes(extension)) {
            error.value = `«${archivo.name}»: formato no admitido. Usa ${props.documentos.formatos.map((f) => f.toUpperCase()).join(', ')}.`;
            continue;
        }

        if (archivo.size > props.documentos.maxKb * 1024) {
            error.value = `«${archivo.name}» pesa ${formatearTamano(archivo.size)} y el máximo es ${formatearTamano(props.documentos.maxKb * 1024)}.`;
            continue;
        }

        archivos.value = [...archivos.value, archivo];
    }
}

function alElegir(evento: Event) {
    const campo = evento.target as HTMLInputElement;
    agregarArchivos(Array.from(campo.files ?? []));
    campo.value = '';
}

function alSoltar(evento: DragEvent) {
    arrastrando.value = false;
    agregarArchivos(Array.from(evento.dataTransfer?.files ?? []));
}

// Una captura copiada (Ctrl+V) se adjunta; el texto se pega normal.
function alPegar(evento: ClipboardEvent) {
    const imagenes = Array.from(evento.clipboardData?.files ?? []).filter(
        esImagen,
    );

    if (!imagenes.length) {
        return;
    }

    evento.preventDefault();
    agregarArchivos(
        imagenes.map(
            (imagen, indice) =>
                new File(
                    [imagen],
                    `Captura ${archivos.value.length + indice + 1}.${imagen.type.split('/')[1]?.replace('jpeg', 'jpg') ?? 'png'}`,
                    { type: imagen.type },
                ),
        ),
    );
}

function quitar(indice: number) {
    archivos.value = archivos.value.filter((_, i) => i !== indice);
}

async function generar() {
    if (!listo.value) {
        return;
    }

    generando.value = true;
    error.value = null;
    propuesta.value = null;

    const datos = new FormData();
    datos.append('texto', texto.value);
    archivos.value.forEach((archivo) => datos.append('archivos[]', archivo));

    try {
        const respuesta = await fetch(GenerarAgendaController.url(), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': tokenXsrf(),
            },
            body: datos,
        });
        const cuerpo = await respuesta.json().catch(() => null);

        if (!respuesta.ok) {
            error.value =
                respuesta.status === 413
                    ? 'Los archivos son demasiado grandes para el servidor.'
                    : (cuerpo?.message ??
                      'No se pudo generar la agenda. Intenta de nuevo.');

            return;
        }

        propuesta.value = cuerpo;
    } catch {
        error.value = 'No se pudo generar la agenda. Revisa la conexión.';
    } finally {
        generando.value = false;
    }
}

function aplicar(modo: 'reemplazar' | 'agregar') {
    if (propuesta.value?.puntos.length) {
        emit('aplicar', propuesta.value.puntos, modo);
    }
}
</script>

<template>
    <div
        class="relative flex flex-col gap-3 rounded-xl border border-hud-acento/25 bg-hud-superficie/20 p-4"
        @dragover.prevent="arrastrando = !generando"
        @dragleave.self="arrastrando = false"
        @drop.prevent="alSoltar"
    >
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3
                    class="flex items-center gap-2 text-sm font-semibold text-hud-texto"
                >
                    <Sparkles class="size-4 text-hud-acento-texto" />
                    Generar agenda
                </h3>
                <p class="text-xs text-hud-suave">
                    Adjunta capturas, un PDF o un Word con la agenda (o pega una
                    captura con Ctrl+V), o pega su texto. La IA arma los puntos
                    y tú decides si usarlos.
                </p>
            </div>
            <button
                type="button"
                class="shrink-0 rounded p-0.5 text-hud-suave hover:bg-hud-superficie hover:text-hud-texto"
                aria-label="Cerrar"
                @click="emit('cerrar')"
            >
                <X class="size-4" />
            </button>
        </div>

        <input
            ref="selector"
            type="file"
            multiple
            class="hidden"
            :accept="formatosAceptados"
            @change="alElegir"
        />

        <button
            type="button"
            class="flex items-center justify-center gap-2 rounded-lg border border-dashed px-3 py-3 text-sm transition-colors"
            :class="
                arrastrando
                    ? 'border-hud-acento bg-hud-acento/5 text-hud-texto'
                    : 'border-hud-acento/30 text-hud-texto/70 hover:border-hud-acento/50 hover:bg-hud-superficie/50'
            "
            :disabled="generando || archivos.length >= MAX_ARCHIVOS"
            @click="selector?.click()"
        >
            <Paperclip class="size-4 shrink-0" />
            {{
                arrastrando
                    ? 'Suelta los archivos aquí'
                    : `Elegir o arrastrar archivos (hasta ${MAX_ARCHIVOS})`
            }}
        </button>

        <ul v-if="archivos.length" class="flex flex-col gap-1.5">
            <li
                v-for="(archivo, indice) in archivos"
                :key="`${archivo.name}-${indice}`"
                class="flex items-center gap-2 rounded-lg border border-hud-acento/20 bg-hud-fondo/70 px-2.5 py-1.5 text-sm text-hud-texto"
            >
                <FileText class="size-4 shrink-0 text-hud-acento-texto" />
                <span class="min-w-0 flex-1 truncate">{{ archivo.name }}</span>
                <span class="shrink-0 text-xs text-hud-suave">
                    {{ formatearTamano(archivo.size) }}
                </span>
                <button
                    type="button"
                    class="shrink-0 rounded p-0.5 text-hud-suave hover:bg-hud-superficie hover:text-hud-texto"
                    :aria-label="`Quitar ${archivo.name}`"
                    :disabled="generando"
                    @click="quitar(indice)"
                >
                    <X class="size-3.5" />
                </button>
            </li>
        </ul>

        <textarea
            v-model="texto"
            rows="3"
            maxlength="20000"
            placeholder="O pega aquí el texto de la agenda…"
            aria-label="Texto de la agenda"
            class="w-full resize-y rounded-lg border border-hud-acento/20 bg-transparent px-3 py-2 text-sm text-hud-texto placeholder:text-hud-suave/70 focus:border-hud-acento focus:outline-none"
            @paste="alPegar"
        />

        <p
            v-if="error"
            class="flex items-center gap-2 text-xs text-rose-600"
            role="alert"
        >
            <CircleAlert class="size-3.5 shrink-0" />
            {{ error }}
        </p>

        <Button
            size="sm"
            class="self-start bg-hud-acento text-hud-sobre-acento hover:bg-hud-acento-fuerte"
            :disabled="!listo"
            @click="generar"
        >
            <LoaderCircle v-if="generando" class="animate-spin" />
            <Sparkles v-else />
            {{ generando ? 'Leyendo la agenda…' : 'Generar agenda' }}
        </Button>

        <div v-if="propuesta" class="flex flex-col gap-2 border-t pt-3">
            <p
                v-if="propuesta.aviso"
                class="flex items-start gap-2 text-xs text-amber-700"
            >
                <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
                {{ propuesta.aviso }}
            </p>

            <template v-if="propuesta.puntos.length">
                <p class="text-xs font-medium text-hud-suave">
                    Agenda propuesta ({{ propuesta.puntos.length }} puntos):
                </p>
                <ol class="flex flex-col gap-1.5">
                    <li
                        v-for="(punto, indice) in propuesta.puntos"
                        :key="indice"
                        class="flex gap-2 text-sm text-hud-texto"
                    >
                        <span
                            class="w-5 shrink-0 font-mono text-xs leading-5 text-hud-acento-texto"
                        >
                            {{ indice + 1 }}.
                        </span>
                        <span class="min-w-0 flex-1">{{ punto }}</span>
                    </li>
                </ol>
                <div class="flex flex-wrap gap-2 pt-1">
                    <Button
                        size="sm"
                        class="bg-hud-acento text-hud-sobre-acento hover:bg-hud-acento-fuerte"
                        @click="aplicar('reemplazar')"
                    >
                        {{
                            puntosActuales
                                ? 'Reemplazar la agenda'
                                : 'Usar esta agenda'
                        }}
                    </Button>
                    <Button
                        v-if="puntosActuales"
                        size="sm"
                        variant="outline"
                        class="border-hud-acento/40 bg-transparent text-hud-texto hover:bg-hud-superficie"
                        @click="aplicar('agregar')"
                    >
                        Agregar a la agenda
                    </Button>
                </div>
            </template>
        </div>
    </div>
</template>
