<script setup lang="ts">
import { CircleAlert, LoaderCircle, Plus, SendHorizontal } from '@lucide/vue';
import { nextTick, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { useChat } from '@/composables/useChat';
import { markdownSeguro } from '@/lib/markdown';

const { mensajes, enviando, error, cargar, nueva, enviar } = useChat();

const texto = ref('');
const lista = ref<HTMLElement | null>(null);

const SUGERENCIAS = [
    '¿Qué está publicando Infobae?',
    'Últimos videos del canal @infobae',
    '¿Qué acordamos en la última reunión?',
];

async function bajar() {
    await nextTick();
    lista.value?.scrollTo({ top: lista.value.scrollHeight });
}

async function mandar(mensaje = texto.value) {
    if (!mensaje.trim() || enviando.value) {
        return;
    }

    texto.value = '';
    await enviar(mensaje);
}

function alPresionarTecla(evento: KeyboardEvent) {
    // Enter envía; Shift + Enter hace un salto de línea.
    if (evento.key === 'Enter' && !evento.shiftKey) {
        evento.preventDefault();
        void mandar();
    }
}

watch(mensajes, bajar, { deep: true });

onMounted(async () => {
    await cargar();
    await bajar();
});
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col gap-3">
        <div class="flex items-center justify-between">
            <p class="text-xs text-cyan-200/50">
                Jarvis puede buscar en internet, leer páginas, ver YouTube y
                consultar las reuniones.
            </p>
            <Button
                v-if="mensajes.length"
                size="sm"
                variant="ghost"
                class="shrink-0 text-cyan-200 hover:bg-cyan-950 hover:text-cyan-50"
                :disabled="enviando"
                @click="nueva"
            >
                <Plus /> Nueva
            </Button>
        </div>

        <div
            ref="lista"
            class="flex min-h-[16rem] flex-1 flex-col gap-3 overflow-y-auto pr-1 lg:max-h-[60vh]"
            aria-live="polite"
        >
            <div
                v-if="!mensajes.length"
                class="flex flex-1 flex-col justify-center gap-2"
            >
                <p class="text-sm text-cyan-200/60">Prueba con:</p>
                <button
                    v-for="sugerencia in SUGERENCIAS"
                    :key="sugerencia"
                    type="button"
                    class="rounded-lg border border-cyan-500/20 bg-cyan-950/20 px-3 py-2 text-left text-sm text-cyan-100/80 transition-colors hover:border-cyan-400/50 hover:bg-cyan-950/50"
                    @click="mandar(sugerencia)"
                >
                    {{ sugerencia }}
                </button>
            </div>

            <div
                v-for="(mensaje, indice) in mensajes"
                :key="indice"
                class="flex"
                :class="mensaje.rol === 'usuario' ? 'justify-end' : ''"
            >
                <p
                    v-if="mensaje.rol === 'usuario'"
                    class="max-w-[85%] rounded-2xl rounded-br-sm bg-cyan-400/90 px-3 py-2 text-sm whitespace-pre-wrap text-slate-950"
                >
                    {{ mensaje.contenido }}
                </p>
                <div
                    v-else
                    class="max-w-[95%] rounded-2xl rounded-bl-sm border border-cyan-500/20 bg-cyan-950/30 px-3 py-2 text-sm"
                >
                    <div
                        v-if="mensaje.contenido"
                        class="respuesta-jarvis flex flex-col gap-2 leading-relaxed break-words text-cyan-50/90"
                        v-html="markdownSeguro(mensaje.contenido)"
                    />
                    <p
                        v-if="mensaje.actividad"
                        class="flex items-center gap-2 text-xs text-cyan-300"
                        :class="mensaje.contenido ? 'mt-2' : ''"
                    >
                        <LoaderCircle class="size-3.5 animate-spin" />
                        {{ mensaje.actividad }}
                    </p>
                </div>
            </div>
        </div>

        <p
            v-if="error"
            class="flex items-center gap-2 text-sm text-rose-300"
            role="alert"
        >
            <CircleAlert class="size-4 shrink-0" />
            {{ error }}
        </p>

        <form class="flex items-end gap-2" @submit.prevent="mandar()">
            <textarea
                v-model="texto"
                rows="2"
                maxlength="4000"
                placeholder="Escríbele a Jarvis…"
                aria-label="Mensaje para Jarvis"
                class="min-h-[2.75rem] flex-1 resize-none rounded-lg border border-cyan-500/30 bg-cyan-950/30 px-3 py-2 text-sm text-cyan-50 placeholder:text-cyan-200/40 focus:border-cyan-400 focus:outline-none"
                @keydown="alPresionarTecla"
            />
            <Button
                type="submit"
                size="icon"
                :disabled="enviando || !texto.trim()"
                class="bg-cyan-400 text-slate-950 hover:bg-cyan-300"
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
