export default function Checkbox({ className = '', ...props }) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                '!h-5 !min-h-0 w-5 rounded-md border-slate-300 bg-white text-brand-primary shadow-sm transition checked:border-brand-primary checked:bg-brand-primary focus:ring-2 focus:ring-brand-primary focus:ring-offset-2 dark:border-white/15 dark:bg-white/10 dark:checked:border-brand-primary dark:checked:bg-brand-primary dark:focus:ring-offset-slate-950 ' +
                className
            }
        />
    );
}
