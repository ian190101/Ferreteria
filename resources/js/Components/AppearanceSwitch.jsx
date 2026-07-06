import Icon from './Icon';

export default function AppearanceSwitch({ mode, onModeChange }) {
    const isDark = mode === 'dark';

    return (
        <div className="flex items-center gap-2 rounded-full border border-slate-200/80 bg-white/75 p-1 shadow-sm backdrop-blur-xl dark:border-white/10 dark:bg-white/10">
            <button
                type="button"
                aria-label={isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'}
                aria-pressed={isDark}
                onClick={() => onModeChange(isDark ? 'light' : 'dark')}
                className="relative h-8 w-16 rounded-full bg-slate-200 p-1 transition dark:bg-slate-700"
            >
                <span className={`absolute top-1 flex h-6 w-6 items-center justify-center rounded-full bg-white text-slate-700 shadow-md transition dark:bg-slate-950 dark:text-slate-100 ${isDark ? 'left-9' : 'left-1'}`}>
                    <Icon name={isDark ? 'moon' : 'sun'} className="h-3.5 w-3.5" />
                </span>
                <span className={`absolute top-1.5 text-[10px] font-bold uppercase transition ${isDark ? 'left-2 text-slate-300' : 'right-2 text-slate-500'}`}>
                    {isDark ? 'OS' : 'CL'}
                </span>
            </button>
        </div>
    );
}
