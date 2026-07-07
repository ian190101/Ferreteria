export default function PasswordMatchHint({ password, confirmation }) {
    const hasConfirmation = String(confirmation ?? '').length > 0;

    if (!password && !confirmation) {
        return null;
    }

    if (!hasConfirmation) {
        return (
            <p className="mt-2 text-xs font-medium text-slate-500 dark:text-slate-400">
                Confirma la contrasena para validar que ambos campos coincidan.
            </p>
        );
    }

    const matches = password === confirmation;

    return (
        <p className={`mt-2 text-xs font-semibold ${matches ? 'text-emerald-600 dark:text-emerald-300' : 'text-red-600 dark:text-red-300'}`}>
            {matches ? 'Las contrasenas coinciden.' : 'Las contrasenas no coinciden.'}
        </p>
    );
}
