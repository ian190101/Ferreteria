import assert from 'node:assert/strict';
import test from 'node:test';
import { AxiosHeaders } from 'axios';
import { isInertiaLocationResponse, normalizeSystemError, sanitizeTechnicalMessage } from '../../resources/js/Utils/systemErrorDetails.js';

test('reconoce la recarga de Inertia con cabeceras de Axios y Fetch', () => {
    for (const headers of [
        { 'x-inertia-location': '/purchases/suppliers' },
        { 'X-Inertia-Location': '/inventory/products' },
        new AxiosHeaders({ 'X-Inertia-Location': '/customers' }),
        new Headers({ 'X-Inertia-Location': '/sales' }),
    ]) {
        assert.equal(isInertiaLocationResponse({ status: 409, headers }), true);
    }
});

test('no oculta conflictos reales ni otros errores HTTP', () => {
    for (const response of [
        undefined,
        { status: 409 },
        { status: 409, headers: {} },
        { status: 409, headers: { 'X-Inertia-Location': '' } },
        { status: 409, headers: new Headers({ 'X-Inertia-Location': ' ' }) },
        { status: 500, headers: { 'X-Inertia-Location': '/login' } },
        { status: 422, headers: {} },
    ]) {
        assert.equal(isInertiaLocationResponse(response), false);
    }
});

test('conserva los codigos exactos y la referencia recibida del servidor', () => {
    const details = normalizeSystemError({ response: { status: 500, data: { system_error: {
        id: 'referencia-qa', code: 'DB_23000_1062', http_status: 500, native_code: '1062', sql_state: '23000',
        exception_type: 'Illuminate\\Database\\QueryException', technical_message: 'Duplicate entry [valor oculto]', explanation: 'Revisa el registro duplicado.',
    } } } });
    assert.equal(details.code, 'DB_23000_1062');
    assert.equal(details.native_code, '1062');
    assert.equal(details.sql_state, '23000');
    assert.equal(details.id, 'referencia-qa');
    assert.equal(details.explanation, 'Revisa el registro duplicado.');
});

test('no pierde mensajes de validacion aunque esten agrupados por formulario', () => {
    const details = normalizeSystemError({ code: 'VALIDATION_FAILED', http_status: 422, errors: { formulario: { purchase: ['Stock insuficiente.', 'Compra bloqueada.'] } } });
    assert.equal(details.code, 'VALIDATION_FAILED');
    assert.equal(details.technical_message, 'Stock insuficiente. Compra bloqueada.');
    assert.match(details.explanation, /Revisa los datos/);
});

test('conserva el codigo de red del cliente y distingue la ausencia de respuesta HTTP', () => {
    const details = normalizeSystemError({ name: 'AxiosError', code: 'ERR_NETWORK', message: 'Network Error' });
    assert.equal(details.code, 'ERR_NETWORK');
    assert.equal(details.http_status, null);
    assert.equal(details.technical_message, 'Network Error');
});

test('identifica una respuesta HTTP sin sobre de error estructurado', () => {
    const details = normalizeSystemError({ response: { status: 404, data: { message: 'Registro inexistente.' } } });
    assert.equal(details.code, 'HTTP_404');
    assert.equal(details.technical_message, 'Registro inexistente.');
});

test('identifica el tipo real de un error de JavaScript', () => {
    const details = normalizeSystemError(new TypeError('Dato inesperado.'));
    assert.equal(details.code, 'JS_TYPEERROR');
    assert.equal(details.exception_type, 'TypeError');
    assert.equal(details.technical_message, 'Dato inesperado.');
});

test('oculta claves tokens y credenciales de URLs sin perder el resto del diagnostico', () => {
    const message = sanitizeTechnicalMessage('password="clave-qa" token=token-qa Authorization: Bearer credencial-qa mysql://usuario:secreto-qa@servidor/base HTTP_500');
    for (const secret of ['clave-qa', 'token-qa', 'credencial-qa', 'secreto-qa']) assert.equal(message.includes(secret), false);
    assert.match(message, /HTTP_500/);
    assert.equal(sanitizeTechnicalMessage('-----BEGIN PRIVATE KEY-----\ncontenido-privado-qa\n-----END PRIVATE KEY-----').includes('contenido-privado-qa'), false);
});
