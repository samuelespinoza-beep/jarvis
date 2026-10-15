/**
 * Token CSRF que Laravel deja en la cookie XSRF-TOKEN, para las peticiones
 * hechas con fetch (las de Inertia lo envían solas).
 */
export function tokenXsrf(): string {
    const cookie = document.cookie
        .split('; ')
        .find((parte) => parte.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
}
