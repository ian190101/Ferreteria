export function sanitizeTechnicalMessage(value) {
    return String(value ?? '')
        .replace(/\b(mysql|postgres(?:ql)?|redis|https?):\/\/[^\s/@]+:[^\s/@]+@/gi, '$1://[credenciales ocultas]@')
        .replace(/\b(Bearer\s+)[\w.+/=-]+/gi, '$1[oculto]')
        .replace(/(["']?(?:password|passwd|pwd|token|secret|api[_-]?key|authorization|cookie)["']?\s*[:=]\s*)("[^"]*"|'[^']*'|[^\s,;&]+)/gi, '$1[oculto]')
        .replace(/-----BEGIN [^-]*PRIVATE KEY-----[\s\S]*?-----END [^-]*PRIVATE KEY-----/g, '[clave privada oculta]')
        .slice(0, 4000);
}

export function isInertiaLocationResponse(response) {
    if (response?.status !== 409) return false;

    const headers = response.headers;
    const location = typeof headers?.get === 'function'
        ? headers.get('X-Inertia-Location')
        : Object.entries(headers ?? {}).find(([name]) => name.toLowerCase() === 'x-inertia-location')?.[1];

    // Inertia usa este 409 para recargar los recursos o navegar fuera de la aplicación.
    return typeof location === 'string' && location.trim().length > 0;
}

function messagesFrom(errors) {
    if (typeof errors === 'string') return [errors];
    if (!errors || typeof errors !== 'object') return [];
    return Object.values(errors).flatMap(messagesFrom);
}

export function normalizeSystemError(error) {
    const data = error?.response?.data;
    const provided = data?.system_error ?? error?.system_error ?? (error?.technical_message ? error : null);
    const status = provided?.http_status ?? error?.response?.status ?? error?.http_status ?? null;
    const messages = messagesFrom(data?.errors ?? error?.errors);
    const technicalMessage = provided?.technical_message ?? (messages.join(' ') || data?.message || error?.message || 'No se recibió un detalle técnico adicional.');

    return {
        id: provided?.id ?? error?.id ?? globalThis.crypto?.randomUUID?.() ?? `LOCAL-${Date.now()}`,
        code: provided?.code ?? error?.code ?? (status ? `HTTP_${status}` : `JS_${(error?.name ?? 'ERROR').toUpperCase()}`),
        http_status: status,
        native_code: provided?.native_code ?? null,
        sql_state: provided?.sql_state ?? null,
        exception_type: provided?.exception_type ?? error?.name ?? (status ? 'Respuesta HTTP' : 'Error del navegador'),
        technical_message: sanitizeTechnicalMessage(technicalMessage),
        explanation: provided?.explanation ?? error?.explanation ?? (status === 422
            ? 'Revisa los datos o las condiciones indicadas en el detalle antes de volver a intentar.'
            : status === 419
                ? 'La sesión venció. Recarga la pantalla e inicia sesión nuevamente.'
                : status
                    ? 'El servidor rechazó la solicitud. Revisa el código y el detalle para conocer la causa.'
                    : 'No se pudo completar la operación en el navegador o la conexión. Revisa tu conexión y vuelve a intentar.'),
    };
}
