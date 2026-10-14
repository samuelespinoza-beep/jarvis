<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

const { analizador = null, activo = false } = defineProps<{
    analizador?: AnalyserNode | null;
    activo?: boolean;
}>();

const contenedor = ref<HTMLDivElement | null>(null);
const lienzo = ref<HTMLCanvasElement | null>(null);

const BARRAS = 72;
const movimientoReducido = window.matchMedia(
    '(prefers-reduced-motion: reduce)',
).matches;

let cuadro = 0;
let observador: ResizeObserver | null = null;
let datos = new Uint8Array(0);
let nivelSuavizado = 0;

function ajustarTamano() {
    const canvas = lienzo.value;
    const caja = contenedor.value;

    if (!canvas || !caja) {
        return;
    }

    const lado = Math.min(caja.clientWidth, caja.clientHeight);
    const escala = window.devicePixelRatio || 1;

    canvas.width = lado * escala;
    canvas.height = lado * escala;
    canvas.style.width = `${lado}px`;
    canvas.style.height = `${lado}px`;
}

function leerNivel(): number {
    if (!analizador) {
        return 0;
    }

    if (datos.length !== analizador.frequencyBinCount) {
        datos = new Uint8Array(analizador.frequencyBinCount);
    }

    analizador.getByteFrequencyData(datos);

    let suma = 0;

    for (const valor of datos) {
        suma += valor;
    }

    return suma / (datos.length * 255);
}

function anillo(
    ctx: CanvasRenderingContext2D,
    radio: number,
    grosor: number,
    color: string,
    segmentos: number,
    hueco: number,
    giro: number,
) {
    const paso = (Math.PI * 2) / segmentos;

    ctx.strokeStyle = color;
    ctx.lineWidth = grosor;

    for (let i = 0; i < segmentos; i++) {
        const inicio = giro + i * paso;

        ctx.beginPath();
        ctx.arc(0, 0, radio, inicio, inicio + paso * (1 - hueco));
        ctx.stroke();
    }
}

function dibujar(tiempo: number) {
    cuadro = requestAnimationFrame(dibujar);

    const canvas = lienzo.value;
    const ctx = canvas?.getContext('2d');

    if (!canvas || !ctx) {
        return;
    }

    const lado = canvas.width;
    const r = lado / 2;
    const t = movimientoReducido ? 0 : tiempo / 1000;
    const nivel = leerNivel();

    nivelSuavizado += (nivel - nivelSuavizado) * 0.2;

    const intensidad = activo ? 1 : 0.45;
    const cian = (alfa: number) => `rgba(34, 211, 238, ${alfa * intensidad})`;
    const respiracion = activo ? 0 : Math.sin(t * 1.2) * 0.03;

    ctx.clearRect(0, 0, lado, lado);
    ctx.save();
    ctx.translate(r, r);

    // Resplandor de fondo.
    const halo = ctx.createRadialGradient(0, 0, r * 0.05, 0, 0, r * 0.95);
    halo.addColorStop(0, cian(0.18 + nivelSuavizado * 0.6));
    halo.addColorStop(1, 'rgba(34, 211, 238, 0)');
    ctx.fillStyle = halo;
    ctx.fillRect(-r, -r, lado, lado);

    // Anillos exteriores girando en sentidos opuestos.
    anillo(ctx, r * 0.92, r * 0.008, cian(0.35), 120, 0.55, t * 0.05);
    anillo(ctx, r * 0.86, r * 0.02, cian(0.55), 6, 0.35, -t * 0.25);
    anillo(ctx, r * 0.8, r * 0.006, cian(0.4), 3, 0.15, t * 0.4);

    // Barras radiales del espectro de audio.
    const radioBarras = r * 0.52;

    for (let i = 0; i < BARRAS; i++) {
        const indice = Math.floor((i / BARRAS) * (datos.length * 0.7));
        const valor = analizador ? (datos[indice] ?? 0) / 255 : 0;
        const largo = r * (0.03 + valor * 0.22 + respiracion);
        const angulo = (i / BARRAS) * Math.PI * 2 + t * 0.1;

        ctx.save();
        ctx.rotate(angulo);
        ctx.fillStyle = cian(0.35 + valor * 0.65);
        ctx.fillRect(radioBarras, -r * 0.006, largo, r * 0.012);
        ctx.restore();
    }

    anillo(ctx, r * 0.48, r * 0.012, cian(0.7), 4, 0.08, -t * 0.6);

    // Núcleo.
    const radioNucleo = r * (0.3 + nivelSuavizado * 0.18 + respiracion);
    const nucleo = ctx.createRadialGradient(0, 0, 0, 0, 0, radioNucleo);
    nucleo.addColorStop(0, `rgba(236, 254, 255, ${0.95 * intensidad})`);
    nucleo.addColorStop(0.35, cian(0.85));
    nucleo.addColorStop(1, 'rgba(8, 47, 73, 0)');
    ctx.fillStyle = nucleo;
    ctx.beginPath();
    ctx.arc(0, 0, radioNucleo, 0, Math.PI * 2);
    ctx.fill();

    ctx.restore();
}

onMounted(() => {
    ajustarTamano();
    observador = new ResizeObserver(ajustarTamano);

    if (contenedor.value) {
        observador.observe(contenedor.value);
    }

    cuadro = requestAnimationFrame(dibujar);
});

onBeforeUnmount(() => {
    cancelAnimationFrame(cuadro);
    observador?.disconnect();
});
</script>

<template>
    <div
        ref="contenedor"
        class="flex aspect-square w-full items-center justify-center"
    >
        <canvas ref="lienzo" aria-hidden="true" />
    </div>
</template>
