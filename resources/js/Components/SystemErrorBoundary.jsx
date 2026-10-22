import { Component } from 'react';
import { normalizeSystemError, showSystemError } from '@/Utils/systemErrors';

export default class SystemErrorBoundary extends Component {
    state = { error: null };

    static getDerivedStateFromError(error) {
        return { error: normalizeSystemError(error) };
    }

    componentDidCatch(error) {
        void showSystemError(this.state.error ?? error);
    }

    render() {
        if (!this.state.error) return this.props.children;
        const error = this.state.error;

        return (
            <main className="mx-auto max-w-2xl space-y-4 p-6" role="alert">
                <h1 className="text-xl font-bold">No se pudo mostrar esta pantalla</h1>
                <p>{error.explanation}</p>
                <p>Código: {error.code}</p>
                <p className="break-words">Detalle técnico: {error.exception_type}: {error.technical_message}</p>
                <p>Referencia: {error.id}</p>
                <button type="button" className="rounded border px-4 py-2" onClick={() => window.location.reload()}>Recargar pantalla</button>
            </main>
        );
    }
}
