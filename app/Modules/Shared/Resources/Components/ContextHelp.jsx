export default function ContextHelp({ title = 'Ayuda', children }) {
    if (!children) {
        return null;
    }

    return (
        <details className="group relative inline-block">
            <summary
                className="flex h-5 w-5 cursor-pointer list-none items-center justify-center rounded-full border border-brand-primary/40 text-[11px] font-bold text-brand-primary transition hover:bg-brand-primary hover:text-white focus:outline-none focus:ring-2 focus:ring-brand-primary/30"
                title={title}
                aria-label={title}
            >
                ?
            </summary>
            <div className="absolute left-0 z-30 mt-2 w-72 rounded-xl border border-slate-200 bg-white p-3 text-xs leading-relaxed text-slate-600 shadow-xl dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300 sm:left-auto sm:right-0">
                <p className="mb-1 font-semibold text-slate-900 dark:text-white">{title}</p>
                <div>{children}</div>
            </div>
        </details>
    );
}
