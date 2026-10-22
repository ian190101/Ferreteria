export default function PaymentCashContext({ sessionId }) {
    return sessionId ? (
        <span className="text-sm text-slate-600 dark:text-slate-300">Caja #{sessionId}</span>
    ) : (
        <span className="inline-flex rounded-md bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-900 dark:bg-amber-950 dark:text-amber-200">
            Sin caja abierta al cobrar
        </span>
    );
}
