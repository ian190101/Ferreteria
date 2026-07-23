import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import ContextHelp from './ContextHelp';

export default function SelectField({ label, name, error, children, helpText, helpTooltip, helpTitle, ...props }) {
    return (
        <div>
            <div className="flex items-center gap-2">
                <InputLabel htmlFor={name} value={label} />
                <ContextHelp title={helpTitle}>{helpTooltip}</ContextHelp>
            </div>
            <select
                id={name}
                name={name}
                className="mt-1 block w-full rounded-2xl border-slate-200 bg-white/80 shadow-sm backdrop-blur focus:border-brand-primary focus:ring-brand-primary dark:border-white/10 dark:bg-white/10 dark:text-slate-100"
                {...props}
            >
                {children}
            </select>
            {helpText ? <p className="mt-1 text-xs leading-relaxed text-slate-500 dark:text-slate-400">{helpText}</p> : null}
            <InputError message={error} className="mt-2" />
        </div>
    );
}
