import assert from 'node:assert/strict';
import test from 'node:test';
import axios from 'axios';
import { installSystemErrorHandlers, showSystemError } from '../../resources/js/Utils/systemErrors.js';

test('los manejadores globales permiten las recargas de Inertia y conservan los errores reales', async () => {
    const originalWindow = globalThis.window;
    const originalDocument = globalThis.document;
    const listeners = new Map();
    const dialogs = [];
    let renderedErrors = 0;
    let nextResponse;
    globalThis.window = {
        fetch: async () => nextResponse,
        addEventListener: (name, handler) => listeners.set(name, handler),
        alert: (message) => dialogs.push(message),
    };
    globalThis.document = {
        addEventListener: (name, handler) => listeners.set(name, handler),
        removeEventListener: (name) => listeners.delete(name),
        createElement: () => {
            renderedErrors += 1;
            return { style: {} };
        },
    };
    const interceptorCount = axios.interceptors.response.handlers.length;

    try {
        installSystemErrorHandlers();
        const navigation = new axios.AxiosError('Request failed with status code 409', 'ERR_BAD_REQUEST', {}, null, {
            status: 409,
            headers: new axios.AxiosHeaders({ 'X-Inertia-Location': '/purchases/suppliers' }),
            data: '',
        });
        await assert.rejects(axios.get('/purchases/suppliers', {
            adapter: async () => { throw navigation; },
        }), (error) => error === navigation);
        await showSystemError(navigation);
        listeners.get('unhandledrejection')({ reason: navigation });

        nextResponse = new Response('', { status: 409, headers: { 'X-Inertia-Location': '/inventory/products' } });
        assert.equal(await window.fetch('/inventory/products'), nextResponse);
        assert.equal(renderedErrors, 0);
        assert.equal(dialogs.length, 0);

        await showSystemError(new axios.CanceledError());
        await showSystemError(new DOMException('Solicitud cancelada', 'AbortError'));
        assert.equal(renderedErrors, 0);

        await showSystemError({ response: { status: 409, data: { message: 'El registro cambió.' } } });
        assert.equal(renderedErrors, 1);
        assert.match(dialogs[0], /El registro cambió/);

        nextResponse = new Response(JSON.stringify({ message: 'Acceso denegado.' }), { status: 403 });
        assert.equal(await window.fetch('/customers'), nextResponse);
        await new Promise((resolve) => setImmediate(resolve));
        assert.equal(renderedErrors, 2);
        assert.match(dialogs[1], /Acceso denegado/);
        assert.equal(nextResponse.bodyUsed, false);
    } finally {
        for (let index = interceptorCount; index < axios.interceptors.response.handlers.length; index += 1) {
            axios.interceptors.response.eject(index);
        }
        globalThis.window = originalWindow;
        globalThis.document = originalDocument;
    }
});
