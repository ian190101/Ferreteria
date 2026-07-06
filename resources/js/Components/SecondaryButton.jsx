export default function SecondaryButton({
    type = 'button',
    className = '',
    disabled,
    children,
    ...props
}) {
    return (
        <button
            {...props}
            type={type}
            className={
                `inline-flex min-h-10 items-center justify-center rounded-full border border-slate-200/80 bg-white/80 px-5 py-2 text-sm font-semibold text-slate-700 shadow-sm backdrop-blur transition duration-150 ease-in-out hover:border-brand-primary/40 hover:bg-white focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-2 disabled:opacity-25 dark:border-white/10 dark:bg-white/10 dark:text-slate-200 dark:hover:bg-white/10 dark:focus:ring-offset-slate-950 ${
                    disabled && 'opacity-25'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
