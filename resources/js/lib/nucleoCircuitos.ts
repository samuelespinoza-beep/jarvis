import * as THREE from 'three';
import { EffectComposer } from 'three/examples/jsm/postprocessing/EffectComposer.js';
import { RenderPass } from 'three/examples/jsm/postprocessing/RenderPass.js';
import { UnrealBloomPass } from 'three/examples/jsm/postprocessing/UnrealBloomPass.js';

/**
 * Núcleo del asistente en 3D: una esfera de circuitos dorados con un "ojo" al
 * centro (túnel de anillos y un nudo brillante), rayos que convergen en él y
 * arcos grandes alrededor. Se dibuja con WebGL (Three.js).
 */

export type EstadoNucleo =
    | 'reposo'
    | 'escuchando'
    | 'pensando'
    | 'hablando'
    | 'trabajando';

type Ajustes = {
    giro: number;
    brillo: number;
    // 1 mientras usa una herramienta: los datos corren por los circuitos.
    actividad: number;
};

const AJUSTES: Record<EstadoNucleo, Ajustes> = {
    reposo: { giro: 0.04, brillo: 0.5, actividad: 0 },
    escuchando: { giro: 0.08, brillo: 0.85, actividad: 0.15 },
    // Respuesta simple (un "hola"): apenas más vivo que escuchando. Acelera solo "trabajando".
    pensando: { giro: 0.1, brillo: 0.9, actividad: 0.25 },
    hablando: { giro: 0.1, brillo: 1, actividad: 0.2 },
    trabajando: { giro: 0.14, brillo: 0.9, actividad: 1 },
};

export type Nucleo = {
    cambiarEstado: (estado: EstadoNucleo) => void;
    destruir: () => void;
};

type Punto = [number, number, number];
type Trazos = { pos: number[]; t: number[]; fase: number[]; luz: number[] };

const TAU = Math.PI * 2;
// Distancia de la cámara: cuanto menor, más grande se ve el núcleo dentro de su recuadro.
const DISTANCIA = 6.0;
const azar = (a: number, b: number) => a + Math.random() * (b - a);
const gauss = () => (Math.random() + Math.random() + Math.random() - 1.5) / 1.5;
const limitar = (x: number, a: number, b: number) =>
    Math.max(a, Math.min(b, x));
const enEsfera = (r: number, lat: number, lon: number): Punto => [
    r * Math.cos(lat) * Math.cos(lon),
    r * Math.sin(lat),
    r * Math.cos(lat) * Math.sin(lon),
];
const enArco = (x: number, a: number, b: number) =>
    (((x - a) % TAU) + TAU) % TAU < b - a;

const VERTICES_LINEAS = `uniform float uTiempo, uBrillo, uActividad, uPulso, uChispa, uVel;
    attribute float aT, aFase, aLuz; varying float vLuz;
    void main(){
        vec4 mv = modelViewMatrix*vec4(position, 1.0);
        vec3 dir = normalize((modelViewMatrix*vec4(position, 0.0)).xyz + vec3(0.0, 0.0, 0.0001));
        float frente = 0.22 + 0.78*(dir.z*0.5 + 0.5);
        float parpadeo = 0.8 + 0.2*sin(uTiempo*1.7 + aFase*31.0);
        float x = fract(uPulso*uVel + aFase);
        float dato = smoothstep(0.15, 0.0, abs(aT - x));
        vLuz = aLuz*frente*parpadeo*(1.0 + uChispa*(0.4 + 1.8*uActividad)*dato)*uBrillo;
        gl_Position = projectionMatrix*mv;
    }`;

/**
 * Crea el núcleo sobre el lienzo. "nivel" devuelve el volumen de la voz
 * (0 a 1): el núcleo respira con él. Devuelve null si no hay WebGL.
 */
export function crearNucleo(
    lienzo: HTMLCanvasElement,
    nivel: () => number,
): Nucleo | null {
    let renderer: THREE.WebGLRenderer;

    try {
        renderer = new THREE.WebGLRenderer({ canvas: lienzo, antialias: true });
    } catch {
        return null;
    }

    const sinMovimiento = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.setClearColor(0x000000, 1);
    const PIXEL = renderer.getPixelRatio();
    const escena = new THREE.Scene();
    const camara = new THREE.PerspectiveCamera(35, 1, 0.1, 50);
    camara.position.set(0, 0, DISTANCIA);
    const composer = new EffectComposer(renderer);
    composer.addPass(new RenderPass(escena, camara));
    composer.addPass(
        new UnrealBloomPass(new THREE.Vector2(512, 512), 0.5, 0.4, 0.2),
    );

    function medir() {
        const { width, height } = lienzo.getBoundingClientRect();

        if (!width || !height) {
            return;
        }

        renderer.setSize(width, height, false);
        composer.setPixelRatio(renderer.getPixelRatio());
        composer.setSize(width, height);
        camara.aspect = width / height;
        // En un recuadro más alto que ancho se aleja la cámara, para que la esfera no se corte a los lados.
        camara.position.z = DISTANCIA / Math.min(1, camara.aspect);
        camara.updateProjectionMatrix();
        // Cambiar el tamaño borra el lienzo: se redibuja en el acto para que no parpadee.
        composer.render();
    }

    const observador = new ResizeObserver(medir);
    observador.observe(lienzo);
    medir();

    const aditivo = {
        transparent: true,
        blending: THREE.AdditiveBlending,
        depthWrite: false,
        depthTest: false,
    };
    const geometrias: THREE.BufferGeometry[] = [];
    const materiales: THREE.ShaderMaterial[] = [];
    const otros: THREE.Material[] = [];
    const texturas: THREE.Texture[] = [];

    // ---------- Líneas: brillan según el lado que mira a la cámara y llevan "datos" que las recorren ----------
    function materialLineas(color: Punto, chispa: number, vel: number) {
        const m = new THREE.ShaderMaterial({
            uniforms: {
                uTiempo: { value: 0 },
                uBrillo: { value: 1 },
                uActividad: { value: 0 },
                uPulso: { value: 0 },
                uChispa: { value: chispa },
                uVel: { value: vel },
                uColor: { value: new THREE.Color(...color) },
            },
            vertexShader: VERTICES_LINEAS,
            fragmentShader: `uniform vec3 uColor; varying float vLuz; void main(){ gl_FragColor = vec4(uColor*vLuz*0.55, 1.0); }`,
            ...aditivo,
        });
        materiales.push(m);

        return m;
    }

    const trazos = (): Trazos => ({ pos: [], t: [], fase: [], luz: [] });

    function seg(
        d: Trazos,
        a: Punto,
        b: Punto,
        ta: number,
        tb: number,
        fase: number,
        la: number,
        lb = la,
    ) {
        d.pos.push(...a, ...b);
        d.t.push(ta, tb);
        d.fase.push(fase, fase);
        d.luz.push(la, lb);
    }

    function lineas(d: Trazos, material: THREE.ShaderMaterial) {
        const g = new THREE.BufferGeometry();
        g.setAttribute('position', new THREE.Float32BufferAttribute(d.pos, 3));
        g.setAttribute('aT', new THREE.Float32BufferAttribute(d.t, 1));
        g.setAttribute('aFase', new THREE.Float32BufferAttribute(d.fase, 1));
        g.setAttribute('aLuz', new THREE.Float32BufferAttribute(d.luz, 1));
        geometrias.push(g);
        const l = new THREE.LineSegments(g, material);
        l.frustumCulled = false;

        return l;
    }

    // Fragmentos rectangulares del borde y terminales de los circuitos.
    const matPuntos = new THREE.ShaderMaterial({
        uniforms: {
            uTiempo: { value: 0 },
            uBrillo: { value: 1 },
            uPixel: { value: PIXEL },
        },
        vertexShader: `uniform float uTiempo, uBrillo, uPixel; attribute float aTam, aAlto, aFase; varying float vLuz, vAlto;
            void main(){
                vec4 mv = modelViewMatrix*vec4(position, 1.0);
                vec3 dir = normalize((modelViewMatrix*vec4(position, 0.0)).xyz);
                vLuz = (0.2 + 0.8*(dir.z*0.5 + 0.5))*(0.65 + 0.35*sin(uTiempo*2.0 + aFase))*uBrillo;
                vAlto = aAlto;
                gl_PointSize = aTam*uPixel;
                gl_Position = projectionMatrix*mv;
            }`,
        fragmentShader: `varying float vLuz, vAlto;
            void main(){
                vec2 c = abs(gl_PointCoord - 0.5);
                if (c.y > vAlto*0.5) discard;
                gl_FragColor = vec4(vec3(1.0, 0.47, 0.13)*vLuz*smoothstep(0.5, 0.3, c.x)*0.85, 1.0);
            }`,
        ...aditivo,
    });
    otros.push(matPuntos);

    type Fragmento = { p: Punto; tam: number; alto: number };

    function puntos(lista: Fragmento[]) {
        const g = new THREE.BufferGeometry();
        g.setAttribute(
            'position',
            new THREE.Float32BufferAttribute(
                lista.flatMap((p) => p.p),
                3,
            ),
        );
        g.setAttribute(
            'aTam',
            new THREE.Float32BufferAttribute(
                lista.map((p) => p.tam),
                1,
            ),
        );
        g.setAttribute(
            'aAlto',
            new THREE.Float32BufferAttribute(
                lista.map((p) => p.alto),
                1,
            ),
        );
        g.setAttribute(
            'aFase',
            new THREE.Float32BufferAttribute(
                lista.map(() => Math.random() * 40),
                1,
            ),
        );
        geometrias.push(g);
        const p = new THREE.Points(g, matPuntos);
        p.frustumCulled = false;

        return p;
    }

    // ---------- Estructura: el eje de la esfera mira casi hacia la cámara ----------
    // Las bandas se ven como anillos alrededor del ojo y la esfera gira como una rueda.
    const nucleo = new THREE.Group();
    escena.add(nucleo);
    const esfera = new THREE.Group();
    esfera.rotation.x = Math.PI / 2 - 0.38;
    nucleo.add(esfera);
    const giratorio = new THREE.Group();
    esfera.add(giratorio);
    const interior = new THREE.Group();
    esfera.add(interior);

    // ---------- Cáscara de circuitos: pistas en ángulo recto, en bandas y con huecos ----------
    const terminales: Fragmento[] = [];

    function circuitos(
        destino: Trazos,
        r: number,
        lat0: number,
        lat1: number,
        cantidad: number,
        huecos: [number, number][],
        luzBase: number,
    ) {
        for (let i = 0; i < cantidad; i++) {
            let lat = azar(lat0, lat1);
            let lon = azar(0, TAU);

            if (huecos.some(([a, b]) => enArco(lon, a, b))) {
                continue;
            }

            const n = 3 + Math.floor(Math.random() * 12);
            const paso = azar(0.012, 0.024);
            const fase = Math.random();
            const luz = luzBase * azar(0.5, 1);
            const sentidoLon = Math.random() < 0.5 ? -1 : 1;
            let dir = 0;
            let signo = 1;
            let quedan = 0;
            let previo = enEsfera(r, lat, lon);

            if (Math.random() < 0.35) {
                terminales.push({ p: previo, tam: 3, alto: 1 });
            }

            for (let k = 0; k < n; k++) {
                if (quedan-- <= 0) {
                    const x = Math.random();
                    dir = x < 0.55 ? 0 : x < 0.8 ? 1 : 2;
                    signo = Math.random() < 0.5 ? -1 : 1;
                    quedan = 1 + Math.floor(Math.random() * 4);
                }

                const dLon = (sentidoLon * paso) / Math.max(0.3, Math.cos(lat));

                if (dir === 0) {
                    lon += dLon;
                } else if (dir === 1) {
                    lat = limitar(lat + signo * paso, lat0, lat1);
                } else {
                    lon += dLon * 0.7;
                    lat = limitar(lat + signo * paso * 0.7, lat0, lat1);
                }

                const p = enEsfera(r, lat, lon);
                seg(destino, previo, p, k / n, (k + 1) / n, fase, luz);
                previo = p;
            }

            terminales.push({ p: previo, tam: 3.5, alto: 1 });
        }
    }

    const huecos = (n: number, min: number, max: number) =>
        Array.from({ length: n }, (): [number, number] => {
            const a = azar(0, TAU);

            return [a, a + azar(min, max)];
        });
    const cascara = trazos();

    for (const [lat0, lat1, cantidad, luz] of [
        [0.02, 0.32, 420, 1],
        [0.42, 0.62, 260, 0.9],
        [0.78, 0.98, 150, 0.8],
        [-0.3, -0.05, 300, 0.7],
        [-0.72, -0.45, 200, 0.55],
    ]) {
        circuitos(cascara, 1.2, lat0, lat1, cantidad, huecos(2, 0.3, 0.9), luz);
    }

    // Fragmentos sueltos de la cáscara exterior, rota en varios tramos.
    circuitos(cascara, 1.36, -0.12, 0.2, 260, huecos(3, 0.6, 1.2), 0.85);
    giratorio.add(
        lineas(cascara, materialLineas([1.0, 0.34, 0.08], 1.4, 0.35)),
    );

    const fragmentos: Fragmento[] = Array.from({ length: 340 }, () => ({
        p: enEsfera(azar(1.15, 1.42), azar(-0.2, 0.3), azar(0, TAU)),
        tam: azar(4, 10),
        alto: azar(0.2, 0.55),
    }));
    giratorio.add(puntos(fragmentos));

    // Líneas largas y rectas que convergen en el ojo.
    const rayos = trazos();

    for (let i = 0; i < 64; i++) {
        const desde = enEsfera(1.2, azar(-0.1, 1.15), azar(0, TAU));
        const a = azar(0, TAU);
        const hasta: Punto = [Math.cos(a) * 0.09, 0, Math.sin(a) * 0.09];
        const fase = Math.random();
        const L = azar(0.35, 0.8);
        const p = (u: number): Punto => [
            desde[0] + (hasta[0] - desde[0]) * u,
            desde[1] + (hasta[1] - desde[1]) * u,
            desde[2] + (hasta[2] - desde[2]) * u,
        ];

        for (let k = 0; k < 10; k++) {
            const u0 = k / 10;
            const u1 = (k + 1) / 10;
            seg(
                rayos,
                p(u0),
                p(u1),
                u0,
                u1,
                fase,
                L * (0.3 + 0.7 * u0),
                L * (0.3 + 0.7 * u1),
            );
        }
    }

    giratorio.add(lineas(rayos, materialLineas([1.0, 0.49, 0.17], 1.6, 0.5)));

    // Esfera interior: círculos concéntricos y algunos circuitos.
    const nucleoInterior = trazos();

    for (let i = 0; i < 9; i++) {
        const lat = -0.4 + i * 0.2;
        const fase = Math.random();

        for (let k = 0; k < 120; k++) {
            if ((k + i * 7) % 40 > 34) {
                continue;
            }

            const l0 = (k / 120) * TAU;
            const l1 = ((k + 1) / 120) * TAU;
            seg(
                nucleoInterior,
                enEsfera(0.62, lat, l0),
                enEsfera(0.62, lat, l1),
                k / 120,
                (k + 1) / 120,
                fase,
                0.45,
            );
        }
    }

    circuitos(nucleoInterior, 0.62, -0.5, 1.3, 260, huecos(1, 0.5, 1), 0.6);
    interior.add(
        lineas(nucleoInterior, materialLineas([1.0, 0.39, 0.1], 1.2, 0.4)),
    );
    giratorio.add(puntos(terminales));

    // ---------- El ojo: túnel de anillos concéntricos, aros y un nudo brillante ----------
    const ojo = new THREE.Group();
    esfera.add(ojo);
    const tunel = trazos();

    for (let i = 0; i < 11; i++) {
        const r = 0.34 - i * 0.024;
        const y = -i * 0.035;
        const fase = Math.random();
        const corte = Math.floor(Math.random() * 90);

        for (let k = 0; k < 90; k++) {
            if ((k + corte) % 90 < 8) {
                continue;
            }

            const a0 = (k / 90) * TAU;
            const a1 = ((k + 1) / 90) * TAU;
            seg(
                tunel,
                [Math.cos(a0) * r, y, Math.sin(a0) * r],
                [Math.cos(a1) * r, y, Math.sin(a1) * r],
                k / 90,
                (k + 1) / 90,
                fase,
                0.55 + i * 0.03,
            );
        }
    }

    ojo.add(lineas(tunel, materialLineas([1.0, 0.57, 0.23], 1.2, 0.8)));
    const aroOjo = new THREE.Group();
    esfera.add(aroOjo);
    const aros = trazos();

    for (const [r, guion, hueco, luz] of [
        [0.44, 6, 3, 0.6],
        [0.52, 30, 8, 0.45],
        [0.58, 2, 2, 0.35],
    ]) {
        for (let k = 0; k < 180; k++) {
            if (k % (guion + hueco) >= guion) {
                continue;
            }

            const a0 = (k / 180) * TAU;
            const a1 = ((k + 1) / 180) * TAU;
            seg(
                aros,
                [Math.cos(a0) * r, 0.02, Math.sin(a0) * r],
                [Math.cos(a1) * r, 0.02, Math.sin(a1) * r],
                k / 180,
                (k + 1) / 180,
                r,
                luz,
            );
        }
    }

    aroOjo.add(lineas(aros, materialLineas([1.0, 0.49, 0.15], 0.8, 0.6)));

    const nudo = new THREE.Group();
    nucleo.add(nudo);
    const trazoNudo = trazos();

    for (const [p, q, R, r, inclinacion] of [
        [2, 5, 0.17, 0.07, 0],
        [3, 7, 0.13, 0.06, 1.1],
    ]) {
        let previo: Punto | null = null;

        for (let k = 0; k <= 400; k++) {
            const t = (k / 400) * TAU;
            const base: Punto = [
                (R + r * Math.cos(q * t)) * Math.cos(p * t),
                (R + r * Math.cos(q * t)) * Math.sin(p * t),
                r * Math.sin(q * t),
            ];
            const punto: Punto = [
                base[0],
                base[1] * Math.cos(inclinacion) -
                    base[2] * Math.sin(inclinacion),
                base[1] * Math.sin(inclinacion) +
                    base[2] * Math.cos(inclinacion),
            ];

            if (previo) {
                seg(
                    trazoNudo,
                    previo,
                    punto,
                    (k - 1) / 400,
                    k / 400,
                    inclinacion,
                    1.6,
                );
            }

            previo = punto;
        }
    }

    nudo.add(lineas(trazoNudo, materialLineas([1.0, 0.64, 0.28], 1.5, 0.9)));

    // ---------- Arcos grandes: banda ecuatorial, media luna y corte diagonal ----------
    const banda = new THREE.Group();
    banda.rotation.set(0.18, 0, -0.12);
    nucleo.add(banda);
    const trazoBanda = trazos();

    for (let i = 0; i < 6; i++) {
        const r = 1.3 + i * 0.028;
        const fase = Math.random();
        const salto = Math.floor(Math.random() * 7);

        for (let k = 0; k < 240; k++) {
            if ((k * 7 + salto * 13) % 23 < 3 + i) {
                continue;
            }

            const a0 = (k / 240) * TAU;
            const a1 = ((k + 1) / 240) * TAU;
            seg(
                trazoBanda,
                [Math.cos(a0) * r, 0, Math.sin(a0) * r],
                [Math.cos(a1) * r, 0, Math.sin(a1) * r],
                k / 240,
                (k + 1) / 240,
                fase,
                1 - i * 0.1,
            );
        }
    }

    const bandaGira = lineas(
        trazoBanda,
        materialLineas([1.0, 0.41, 0.1], 1.2, 0.25),
    );
    banda.add(bandaGira);

    const mediaLuna = new THREE.Group();
    mediaLuna.rotation.set(0.85, -0.5, 0.4);
    nucleo.add(mediaLuna);
    const trazoLuna = trazos();

    for (let i = 0; i < 16; i++) {
        const r = 0.5 + i * 0.011;
        const ini = azar(0.4, 0.7);
        const fin = azar(3.4, 3.9);
        const fase = Math.random();
        const luz = (u: number) =>
            1.3 * Math.pow(Math.sin(Math.PI * u), 1.5) * (1 - i / 22);

        for (let k = 0; k < 80; k++) {
            const u0 = k / 80;
            const u1 = (k + 1) / 80;
            const a0 = ini + (fin - ini) * u0;
            const a1 = ini + (fin - ini) * u1;
            seg(
                trazoLuna,
                [Math.cos(a0) * r - 0.2, Math.sin(a0) * r, 0],
                [Math.cos(a1) * r - 0.2, Math.sin(a1) * r, 0],
                u0,
                u1,
                fase,
                luz(u0),
                luz(u1),
            );
        }
    }

    mediaLuna.add(
        lineas(trazoLuna, materialLineas([1.0, 0.51, 0.17], 1.3, 0.45)),
    );

    const corte = new THREE.Group();
    nucleo.add(corte);
    const trazoCorte = trazos();

    for (let i = 0; i < 4; i++) {
        const desde: Punto = [-0.95 + i * 0.025, 1.25, 0.2];
        const hasta: Punto = [0.75 + i * 0.025, -1.3, 0.1];
        const fase = Math.random();
        const p = (u: number): Punto => [
            desde[0] + (hasta[0] - desde[0]) * u,
            desde[1] + (hasta[1] - desde[1]) * u,
            desde[2] + (hasta[2] - desde[2]) * u,
        ];

        for (let k = 0; k < 20; k++) {
            const u0 = k / 20;
            const u1 = (k + 1) / 20;
            seg(
                trazoCorte,
                p(u0),
                p(u1),
                u0,
                u1,
                fase,
                0.9 * Math.sin(Math.PI * u0),
                0.9 * Math.sin(Math.PI * u1),
            );
        }
    }

    corte.add(lineas(trazoCorte, materialLineas([1.0, 0.59, 0.26], 1, 0.6)));

    // ---------- Destellos desenfocados (bokeh) ----------
    const N_BOKEH = 120;
    const posBokeh = new Float32Array(N_BOKEH * 3);
    const tamBokeh = new Float32Array(N_BOKEH);
    const faseBokeh = new Float32Array(N_BOKEH);

    for (let i = 0; i < N_BOKEH; i++) {
        const p = new THREE.Vector3(gauss(), gauss(), gauss())
            .normalize()
            .multiplyScalar(Math.cbrt(Math.random()) * 1.7);
        posBokeh.set([p.x, p.y, p.z], i * 3);
        tamBokeh[i] = azar(6, 20);
        faseBokeh[i] = Math.random() * 40;
    }

    const geoBokeh = new THREE.BufferGeometry();
    geoBokeh.setAttribute('position', new THREE.BufferAttribute(posBokeh, 3));
    geoBokeh.setAttribute('aTam', new THREE.BufferAttribute(tamBokeh, 1));
    geoBokeh.setAttribute('aFase', new THREE.BufferAttribute(faseBokeh, 1));
    geometrias.push(geoBokeh);
    const matBokeh = new THREE.ShaderMaterial({
        uniforms: {
            uTiempo: { value: 0 },
            uBrillo: { value: 1 },
            uPixel: { value: PIXEL },
        },
        vertexShader: `uniform float uTiempo, uPixel, uBrillo; attribute float aTam, aFase; varying float vLuz;
            void main(){
                vec3 p = position + 0.04*vec3(sin(uTiempo*0.3 + aFase), cos(uTiempo*0.25 + aFase*1.3), 0.0);
                vec4 mv = modelViewMatrix*vec4(p, 1.0);
                gl_PointSize = aTam*uPixel*(7.4/-mv.z);
                vLuz = (0.45 + 0.55*sin(uTiempo*0.6 + aFase))*uBrillo;
                gl_Position = projectionMatrix*mv;
            }`,
        fragmentShader: `varying float vLuz;
            void main(){
                float d = length(gl_PointCoord - 0.5);
                float disco = smoothstep(0.5, 0.4, d)*(0.6 + 0.4*smoothstep(0.25, 0.45, d));
                gl_FragColor = vec4(vec3(1.0, 0.45, 0.12)*disco*max(vLuz, 0.0)*0.06, 1.0);
            }`,
        ...aditivo,
    });
    otros.push(matBokeh);
    const bokeh = new THREE.Points(geoBokeh, matBokeh);
    bokeh.frustumCulled = false;
    nucleo.add(bokeh);

    // ---------- Luces: disco tenue detrás y brillo cálido al centro ----------
    function textura(paradas: [number, string][]) {
        const c = document.createElement('canvas');
        c.width = c.height = 256;
        const x = c.getContext('2d');

        if (x) {
            const g = x.createRadialGradient(128, 128, 0, 128, 128, 128);

            for (const [p, color] of paradas) {
                g.addColorStop(p, color);
            }

            x.fillStyle = g;
            x.fillRect(0, 0, 256, 256);
        }

        const t = new THREE.CanvasTexture(c);
        texturas.push(t);

        return t;
    }

    const matDisco = new THREE.SpriteMaterial({
        map: textura([
            [0, 'rgba(255,140,40,.035)'],
            [0.62, 'rgba(255,120,30,.015)'],
            [0.7, 'rgba(255,150,50,.05)'],
            [0.74, 'rgba(255,120,30,0)'],
            [1, 'rgba(0,0,0,0)'],
        ]),
        ...aditivo,
    });
    const disco = new THREE.Sprite(matDisco);
    disco.scale.setScalar(3.9);
    escena.add(disco);
    const matCentro = new THREE.SpriteMaterial({
        map: textura([
            [0, 'rgba(255,220,175,1)'],
            [0.1, 'rgba(255,170,80,.7)'],
            [0.35, 'rgba(255,140,40,.1)'],
            [1, 'rgba(255,110,20,0)'],
        ]),
        ...aditivo,
    });
    const centro = new THREE.Sprite(matCentro);
    escena.add(centro);
    otros.push(matDisco, matCentro);

    // ---------- Animación ----------
    let estado: EstadoNucleo = 'reposo';
    const actual = { ...AJUSTES.reposo, nivel: 0, suave: 0 };
    let previo = performance.now();
    let giro = 0;
    let pulso = 0;
    let cuadroPendiente = 0;

    function cuadro(ahora: number) {
        const dt = Math.min(0.05, (ahora - previo) / 1000);
        previo = ahora;
        const t = ahora / 1000;
        const meta = AJUSTES[estado];
        const paso = Math.min(1, dt * 3);
        actual.giro += (meta.giro - actual.giro) * paso;
        actual.brillo += (meta.brillo - actual.brillo) * paso;
        // La actividad sube despacio (algo más de un segundo) y baja un poco más rápido:
        // así una herramienta que responde al instante no da un salto brusco.
        const ritmoActividad = meta.actividad > actual.actividad ? 1.2 : 2;
        actual.actividad +=
            (meta.actividad - actual.actividad) *
            Math.min(1, dt * ritmoActividad);
        actual.nivel += (nivel() - actual.nivel) * Math.min(1, dt * 12);
        // El núcleo sigue la voz con suavidad, como una respiración.
        actual.suave += (actual.nivel - actual.suave) * paso;
        giro += actual.giro * dt;
        // Los datos corren por los circuitos; mucho más rápido al usar una herramienta.
        pulso += (0.4 + actual.giro * 2 + actual.actividad * 5) * dt;

        const B = actual.brillo;
        const escala = 1 + 0.015 * Math.sin(t * 1.3) + actual.suave * 0.07;
        nucleo.scale.setScalar(escala);
        giratorio.rotation.y = giro * 1.2;
        interior.rotation.y = -giro * 2;
        esfera.rotation.z = 0.08 * Math.sin(t * 0.2);
        ojo.rotation.y += (0.3 + actual.giro * 3 + actual.actividad * 5) * dt;
        aroOjo.rotation.y -= (0.15 + actual.giro + actual.actividad * 2) * dt;
        ojo.scale.setScalar(1 + actual.suave * 0.35);
        nudo.rotation.x += (0.4 + actual.actividad * 3) * dt;
        nudo.rotation.y += (0.6 + actual.actividad * 4) * dt;
        bandaGira.rotation.y +=
            (0.08 + actual.giro * 0.5 + actual.actividad * 0.6) * dt;
        mediaLuna.rotation.z += (0.06 + actual.actividad * 0.5) * dt;
        corte.rotation.z = 0.1 * Math.sin(t * 0.15);

        for (const m of materiales) {
            m.uniforms.uTiempo.value = t;
            m.uniforms.uBrillo.value = B;
            m.uniforms.uActividad.value = actual.actividad;
            m.uniforms.uPulso.value = pulso;
        }

        matPuntos.uniforms.uTiempo.value = t;
        matPuntos.uniforms.uBrillo.value = B;
        matBokeh.uniforms.uTiempo.value = t;
        matBokeh.uniforms.uBrillo.value = B;
        centro.scale.setScalar(
            (0.35 +
                actual.suave * 0.5 +
                actual.actividad * 0.15 +
                0.04 * Math.sin(t * 3)) *
                escala,
        );
        matCentro.opacity = 0.4 + 0.45 * B;
        matDisco.opacity = B;

        composer.render();

        if (!sinMovimiento) {
            cuadroPendiente = requestAnimationFrame(cuadro);
        }
    }

    cuadroPendiente = requestAnimationFrame(cuadro);

    return {
        cambiarEstado(nuevo) {
            estado = nuevo;

            // Sin animación continua, se dibuja un cuadro por cada cambio de estado.
            if (sinMovimiento) {
                cuadroPendiente = requestAnimationFrame(cuadro);
            }
        },
        destruir() {
            cancelAnimationFrame(cuadroPendiente);
            observador.disconnect();
            geometrias.forEach((g) => g.dispose());
            materiales.forEach((m) => m.dispose());
            otros.forEach((m) => m.dispose());
            texturas.forEach((t) => t.dispose());
            composer.dispose();
            renderer.dispose();
            // Libera el contexto WebGL: el navegador admite pocos a la vez.
            renderer.forceContextLoss();
        },
    };
}
