import axios from 'axios';
import { router } from '@inertiajs/react';
import { isInertiaLocationResponse, normalizeSystemError } from './systemErrorDetails.js';

const shownObjects = new WeakSet();
const shownReferences = new Set();
let installed = false;

export { normalizeSystemError, sanitizeTechnicalMessage } from './systemErrorDetails.js';

export async function showSystemError(error) {
    if (axios.isCancel(error) || error?.name === 'AbortError') return;
    if (isInertiaLocationResponse(error?.response)) return;
    if (error && typeof error === 'object') {
        if (shownObjects.has(error)) return;
        shownObjects.add(error);
    }
    const details = normalizeSystemError(error);
    if (shownReferences.has(details.id)) return;
    shownReferences.add(details.id);
    if (shownReferences.size > 100) shownReferences.delete(shownReferences.values().next().value);

    const lines = [
        details.explanation,
        '',
        `Código: ${details.code}`,
        details.http_status ? `HTTP: ${details.http_status}` : null,
        details.native_code !== null && details.native_code !== '' ? `Código nativo: ${details.native_code}` : null,
        details.sql_state ? `SQLSTATE: ${details.sql_state}` : null,
        `Tipo técnico: ${details.exception_type}`,
        `Detalle técnico: ${details.technical_message}`,
        `Referencia: ${details.id}`,
    ].filter((line) => line !== null);

    // textContent conserva los saltos de línea sin interpretar HTML de una excepción remota.
    const content = document.createElement('div');
    content.textContent = lines.join('\n');
    content.style.whiteSpace = 'pre-wrap';
    content.style.textAlign = 'left';
    content.style.overflowWrap = 'anywhere';
    try {
        // La biblioteca del diálogo se carga al necesitarla, sin aumentar el arranque de todas las pantallas.
        const { infoAction } = await import('./alerts');
        await infoAction({ title: 'No se pudo completar la operación', html: content, icon: 'error' });
    } catch {
        // Si también falla la descarga del diálogo, el navegador aún puede mostrar el diagnóstico.
        window.alert(lines.join('\n'));
    }
}

export function installSystemErrorHandlers() {
    if (installed) return;
    installed = true;
    axios.interceptors.response.use((response) => response, (error) => {
        void showSystemError(error);
        return Promise.reject(error);
    });
    router.on('invalid', (event) => {
        event.preventDefault();
        void showSystemError({ response: event.detail.response });
    });
    router.on('exception', (event) => {
        event.preventDefault();
        void showSystemError(event.detail.exception);
    });
    window.addEventListener('error', (event) => {
        if (event.error) {
            void showSystemError(event.error);
        } else if (['SCRIPT', 'LINK'].includes(event.target?.tagName)) {
            const source = new URL(event.target.src || event.target.href, window.location.href);
            void showSystemError({ code: 'RESOURCE_LOAD_FAILED', message: `No se pudo cargar ${source.origin}${source.pathname}.` });
        }
    }, true);
    window.addEventListener('unhandledrejection', (event) => void showSystemError(event.reason instanceof Object ? event.reason : new Error(String(event.reason))));

    const originalFetch = window.fetch.bind(window);
    window.fetch = async (...args) => {
        try {
            const response = await originalFetch(...args);
            if (!response.ok && !isInertiaLocationResponse(response)) {
                const data = await response.clone().json().catch(() => null);
                void showSystemError({ response: { status: response.status, headers: response.headers, data } });
            }
            return response;
        } catch (error) {
            void showSystemError(error);
            throw error;
        }
    };
}
