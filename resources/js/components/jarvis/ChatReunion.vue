<script setup lang="ts">
import { FileText, Mic } from '@lucide/vue';
import { esSoloDocumento } from '@/composables/useChat';
import type { MensajeChat } from '@/composables/useChat';
import { formatearHora } from '@/lib/formato';
import { markdownSeguro } from '@/lib/markdown';

defineProps<{
    mensajes: MensajeChat[];
}>();
</script>

<template>
    <p v-if="!mensajes.length" class="text-sm text-muted-foreground">
        No se usó el chat en esta reunión.
    </p>
    <ol v-else class="flex flex-col gap-4 text-sm">
        <li
            v-for="(mensaje, indice) in mensajes"
            :key="mensaje.id ?? indice"
            class="grid grid-cols-[4rem_1fr] gap-3"
        >
            <span class="font-mono text-xs text-muted-foreground">
                {{ mensaje.creado_en ? formatearHora(mensaje.creado_en) : '' }}
            </span>
            <div class="flex min-w-0 flex-col gap-1.5">
                <p
                    class="flex items-center gap-1.5 text-xs font-medium"
                    :class="
                        mensaje.rol === 'usuario'
                            ? 'text-foreground'
                            : 'text-hud-acento-texto'
                    "
                >
                    {{ mensaje.rol === 'usuario' ? 'Usuario' : 'Asistente IA' }}
                    <span
                        v-if="mensaje.en_vivo"
                        class="flex items-center gap-1 font-normal text-muted-foreground"
                    >
                        <Mic class="size-3" /> por voz
                    </span>
                </p>
                <a
                    v-if="mensaje.imagen"
                    :href="mensaje.imagen"
                    target="_blank"
                    rel="noopener"
                    :title="mensaje.documento ?? undefined"
                >
                    <img
                        :src="mensaje.imagen"
                        :alt="mensaje.documento ?? 'Imagen adjunta'"
                        class="max-h-60 rounded-lg border object-contain"
                    />
                </a>
                <span
                    v-else-if="mensaje.documento"
                    class="flex w-fit max-w-full items-center gap-1.5 rounded-md border px-2 py-1 text-xs"
                >
                    <FileText class="size-3.5 shrink-0 text-hud-acento" />
                    <span class="truncate">{{ mensaje.documento }}</span>
                </span>
                <template v-if="!esSoloDocumento(mensaje)">
                    <div
                        v-if="mensaje.rol === 'jarvis'"
                        class="respuesta-jarvis flex flex-col gap-2 leading-relaxed break-words text-foreground/90"
                        v-html="markdownSeguro(mensaje.contenido)"
                    />
                    <p v-else class="leading-relaxed whitespace-pre-wrap">
                        {{ mensaje.contenido }}
                    </p>
                </template>
            </div>
        </li>
    </ol>
</template>

<style scoped>
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
