import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';

export default function SelectField({ label, name, error, children, ...props }) {
    return (
        <div>
            <InputLabel htmlFor={name} value={label} />
            <select
                id={name}
                name={name}
                className="mt-1 block w-full rounded-2xl border-slate-200 bg-white/80 shadow-sm backdrop-blur focus:border-brand-primary focus:ring-brand-primary dark:border-white/10 dark:bg-white/10 dark:text-slate-100"
                {...props}
            >
                {children}
            </select>
            <InputError message={error} className="mt-2" />
        </div>
    );
}
